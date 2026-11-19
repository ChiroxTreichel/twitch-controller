<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

use RuntimeException;
use Throwable;
use TwitchController\Core\App;

/**
 * Wer folgt - und wer nicht mehr.
 *
 * ===================================================================
 *  Ein Unfollow meldet Twitch nicht
 * ===================================================================
 *
 * Fuer einen Follow gibt es channel.follow, fuer das Gegenteil nichts.
 * Darum fuehrt der Kern eine Liste aller Follower (twitch_followers)
 * und vergleicht sie im Takt des Workers mit dem, was Twitch sagt. Wer
 * fehlt, hat entfolgt - das wird als Ereignis gespeichert
 * (twitch.channel.unfollow), mit allem, was daran haengt: Aktivitaeten,
 * OBS-Feed, core.event.stored fuer die Plugins.
 *
 * ===================================================================
 *  Alle zehn Sekunden, aber nicht alles
 * ===================================================================
 *
 * Die ganze Liste alle zehn Sekunden waere bei tausend Followern zehn
 * Anfragen, bei zehntausend hundert - sechsmal pro Minute. Gefragt
 * wird deshalb zuerst nur nach der ersten Seite. Twitch liefert sie
 * neueste zuerst und sagt dazu, wie viele es insgesamt sind:
 *
 *   gleiche Gesamtzahl, gleiche erste Seite  -> nichts passiert
 *   sonst                                    -> ganze Liste holen
 *
 * Ein Unfollow senkt die Gesamtzahl. Ein Unfollow und ein Follow im
 * selben Takt lassen sie stehen - aber der neue Follow steht dann
 * oben auf der ersten Seite. Uebrig bleibt kein Fall, der beides
 * gleich laesst; zur Sicherheit wird trotzdem einmal die Stunde die
 * ganze Liste geholt.
 *
 * ===================================================================
 *  Lieber kein Unfollow als ein falscher
 * ===================================================================
 *
 * Fehlt in einer Antwort eine Seite oder bricht der Abruf ab, sahe
 * das aus wie hundert Unfollows auf einmal. Darum wird nur verglichen,
 * wenn die geholte Liste zur Gesamtzahl passt, und der allererste
 * Abruf legt nur den Stand an - ohne Ereignisse, sonst waere jeder
 * Follower beim Einrichten erst einmal "neu" und keiner "weg".
 */
final class Followers
{
    use HelixList;

    /** So oft wird nachgesehen. */
    public const CHECK_EVERY = 10;

    /** So oft wird die ganze Liste geholt, auch wenn nichts darauf hindeutet. */
    public const FULL_EVERY = 3600;

    public const EVENT = 'twitch.channel.unfollow';

    /**
     * Zuletzt nachgesehen. Im Speicher des Workers und nicht in den
     * Einstellungen: sechs Schreibzugriffe pro Minute nur fuer einen
     * Zeitstempel waeren Laerm in der Datenbank.
     */
    private int $checkedAt = 0;

    /** Erste Seite beim letzten Abruf - fuer core.followers.changed. */
    private string $lastHead = '';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Nachsehen, wenn es Zeit ist. Fuer den Worker.
     *
     * Wirft nie: ein Netzfehler bei Twitch darf den Worker nicht
     * aufhalten. Der Fehler steht im Log und in den Einstellungen.
     */
    public function checkIfDue(): void
    {
        if (time() - $this->checkedAt < self::CHECK_EVERY) {
            return;
        }

        $this->checkedAt = time();

        if (!$this->app->twitch->hasChannel()) {
            return;
        }

        try {
            $this->check();

            if ($this->app->settings->string('followers_error', '') !== '') {
                $this->app->settings->set('followers_error', '');
            }
        } catch (Throwable $fehler) {
            $meldung = mb_substr($fehler->getMessage(), 0, 300);

            // Nur beim Wechsel ins Log - sonst stuende derselbe Fehler
            // alle zehn Sekunden darin.
            if ($this->app->settings->string('followers_error', '') !== $meldung) {
                $this->app->settings->set('followers_error', $meldung);
                $this->app->log('Follower-Abgleich: ' . $meldung);
            }
        }
    }

    /**
     * Wie viele folgen - laut letztem Abgleich. Null, solange es noch
     * keinen gab; dann muss, wer die Zahl braucht, selbst fragen.
     */
    public function total(): ?int
    {
        if ($this->app->settings->int('followers_full_at', 0) <= 0) {
            return null;
        }

        $gesamt = $this->app->settings->int('followers_total', -1);

        return $gesamt >= 0 ? $gesamt : null;
    }

    /**
     * Einmal vergleichen.
     *
     * @return int so viele Unfollows wurden eingetragen
     */
    public function check(): int
    {
        $kanal = $this->app->twitch->broadcasterId();
        $erste = $this->helixPage('channels/followers', $kanal, '');

        $gesamt = $erste['total'];
        $kopf = self::helixHead($erste['data'], ['user_id', 'followed_at']);

        $angelegt = $this->app->settings->int('followers_full_at', 0) > 0;
        $faellig = time() - $this->app->settings->int('followers_full_at', 0) >= self::FULL_EVERY;

        if ($angelegt && !$faellig
            && $gesamt === $this->app->settings->int('followers_total', -1)
            && $kopf === $this->app->settings->string('followers_head', '')) {
            return 0;
        }

        // Ohne Zeitpunkt des Follows ist eine Zeile nicht zu gebrauchen.
        $alle = array_filter(
            $this->helixAll('channels/followers', $kanal, $erste),
            static fn (array $zeile): bool => (string) ($zeile['followed_at'] ?? '') !== ''
        );

        // Fehlt eine Seite, waeren die Fehlenden keine Unfollows.
        if (!self::helixComplete(count($alle), $gesamt)) {
            throw new RuntimeException(sprintf(
                'Liste unvollstaendig (%d von %d) - diesmal kein Vergleich.',
                count($alle),
                $gesamt
            ));
        }

        $weg = $this->store($alle, $angelegt);
        $vorher = $this->app->settings->int('followers_total', -1);

        $this->app->settings->set('followers_total', $gesamt);
        $this->app->settings->set('followers_head', $kopf);
        $this->app->settings->set('followers_full_at', time());

        foreach ($weg as $zeile) {
            $this->unfollowed($zeile);
        }

        /*
         * Fuer Plugins, die die Zahl anzeigen - etwa ein Follower-Ziel
         * im Overlay. Sie muessen dann nicht selbst bei Twitch fragen,
         * und die Anzeige folgt einem Follow wie einem Unfollow nach
         * hoechstens zehn Sekunden.
         *
         * Auch beim stuendlichen Abruf ohne Aenderung? Nein - nur wenn
         * sich die Zahl oder die Liste geaendert hat.
         */
        if ($gesamt !== $vorher || $weg !== [] || $kopf !== $this->lastHead) {
            $this->app->hooks->dispatch('core.followers.changed', $gesamt);
        }

        $this->lastHead = $kopf;

        return count($weg);
    }

    /**
     * Die Liste in die Tabelle schreiben und zurueckgeben, wer fehlt.
     *
     * @param array<string, array<string, mixed>> $alle nach user_id
     * @return list<array<string, mixed>> die Zeilen derer, die fehlen
     */
    private function store(array $alle, bool $vergleichen): array
    {
        return $this->app->db->transaction(function () use ($alle, $vergleichen): array {
            $stand = (string) $this->app->db->value('SELECT now()::text');

            foreach ($alle as $id => $zeile) {
                $this->app->db->run(
                    'INSERT INTO twitch_followers (user_id, login, name, followed_at, seen_at)
                     VALUES (:id, :login, :name, CAST(:seit AS timestamptz), CAST(:stand AS timestamptz))
                     ON CONFLICT (user_id) DO UPDATE
                        SET login = EXCLUDED.login, name = EXCLUDED.name,
                            followed_at = EXCLUDED.followed_at, seen_at = EXCLUDED.seen_at',
                    [
                        'id'    => (string) $id,
                        'login' => (string) ($zeile['user_login'] ?? ''),
                        'name'  => (string) ($zeile['user_name'] ?? $zeile['user_login'] ?? ''),
                        'seit'  => (string) $zeile['followed_at'],
                        'stand' => $stand,
                    ]
                );
            }

            $weg = $vergleichen
                ? $this->app->db->all(
                    'SELECT user_id, login, name, followed_at FROM twitch_followers
                      WHERE seen_at < CAST(:stand AS timestamptz)',
                    ['stand' => $stand]
                )
                : [];

            $this->app->db->run(
                'DELETE FROM twitch_followers WHERE seen_at < CAST(:stand AS timestamptz)',
                ['stand' => $stand]
            );

            return $weg;
        });
    }

    /**
     * Den Unfollow als Ereignis speichern - wie jedes andere auch.
     *
     * Der Schluessel enthaelt den Zeitpunkt des Follows: wer folgt,
     * geht, wieder folgt und wieder geht, hat zwei Unfollows - aber
     * derselbe Unfollow wird nie zweimal eingetragen.
     *
     * @param array<string, mixed> $zeile
     */
    private function unfollowed(array $zeile): void
    {
        $nutzlast = [
            'user_id'                => (string) $zeile['user_id'],
            'user_login'             => (string) $zeile['login'],
            'user_name'              => (string) $zeile['name'],
            'broadcaster_user_id'    => $this->app->twitch->broadcasterId(),
            'broadcaster_user_login' => $this->app->twitch->broadcasterLogin(),
            'followed_at'            => (string) $zeile['followed_at'],
        ];

        $this->app->events->store(
            'twitch',
            self::EVENT,
            'unfollow:' . $nutzlast['user_id'] . ':' . $nutzlast['followed_at'],
            date('Y-m-d H:i:sP'),
            $this->app->normalizer->normalize('twitch', self::EVENT, $nutzlast),
            $nutzlast,
            (string) json_encode($nutzlast, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
