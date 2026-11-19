<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

use RuntimeException;
use Throwable;
use TwitchController\Core\App;

/**
 * Wer ein Abo hat - als Liste fuer die Plugins.
 *
 * Wie Followers, mit einem Unterschied: hier entstehen KEINE
 * Ereignisse. Neues Abo, Verlaengerung, Geschenk und Ende meldet Twitch
 * selbst (channel.subscribe, channel.subscription.*). Ein zweites
 * "Abo beendet" aus diesem Vergleich kaeme mit anderem Zeitpunkt
 * dazu - dasselbe Abo, zweimal gemeldet, wie es Events\Subscriptions
 * fuer neue Abos muehsam verhindert.
 *
 * Die Liste ist dafuer da, dass nicht jedes Plugin selbst fragt: wer
 * Abos zaehlt oder auflistet, liest twitch_subscribers oder hoert auf
 * core.subscribers.changed.
 *
 * Abgefragt wird im selben Takt und mit demselben Trick wie die
 * Follower: erste Seite und Gesamtzahl, die ganze Liste nur bei einer
 * Aenderung und einmal die Stunde. Bei den meisten Kanaelen ist die
 * erste Seite ohnehin die ganze Liste.
 *
 * In der Liste steht auch der Kanalinhaber - Twitch fuehrt ihn als
 * Abonnenten seines eigenen Kanals (bei uns mit Stufe 3). Gesamtzahl
 * und Punkte zaehlen ihn NICHT mit, die Liste hat also eine Zeile mehr.
 * Wer echte Abonnenten auflistet, laesst ihn weg.
 */
final class Subscribers
{
    use HelixList;

    public const CHECK_EVERY = 10;

    public const FULL_EVERY = 3600;

    private int $checkedAt = 0;

    public function __construct(private readonly App $app)
    {
    }

    /** Fuer den Worker. Wirft nie - siehe Followers::checkIfDue(). */
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

            if ($this->app->settings->string('subscribers_error', '') !== '') {
                $this->app->settings->set('subscribers_error', '');
            }
        } catch (Throwable $fehler) {
            $meldung = mb_substr($fehler->getMessage(), 0, 300);

            if ($this->app->settings->string('subscribers_error', '') !== $meldung) {
                $this->app->settings->set('subscribers_error', $meldung);
                $this->app->log('Abo-Liste: ' . $meldung);
            }
        }
    }

    /**
     * Gesamtzahl laut Twitch - ohne den Kanalinhaber, der in der Liste
     * steht. Null vor dem ersten Abgleich.
     */
    public function total(): ?int
    {
        return $this->stored('subscribers_total');
    }

    /** Abo-Punkte laut Twitch (Stufe 2 zaehlt doppelt, Stufe 3 sechsfach). */
    public function points(): ?int
    {
        return $this->stored('subscribers_points');
    }

    /**
     * Einmal vergleichen.
     *
     * @return bool ob sich etwas geaendert hat
     */
    public function check(): bool
    {
        $kanal = $this->app->twitch->broadcasterId();
        $erste = $this->helixPage('subscriptions', $kanal, '');

        $gesamt = $erste['total'];
        $punkte = (int) ($erste['json']['points'] ?? 0);
        $kopf = self::helixHead($erste['data'], ['user_id', 'tier', 'is_gift', 'gifter_id']);

        $stand = $this->app->settings->int('subscribers_full_at', 0);
        $gleich = $gesamt === $this->app->settings->int('subscribers_total', -1)
            && $punkte === $this->app->settings->int('subscribers_points', -1)
            && $kopf === $this->app->settings->string('subscribers_head', '');

        if ($stand > 0 && $gleich && time() - $stand < self::FULL_EVERY) {
            return false;
        }

        $alle = $this->helixAll('subscriptions', $kanal, $erste);

        if (!self::helixComplete(count($alle), $gesamt)) {
            throw new RuntimeException(sprintf(
                'Liste unvollstaendig (%d von %d) - diesmal kein Abgleich.',
                count($alle),
                $gesamt
            ));
        }

        $this->store($alle);

        $this->app->settings->set('subscribers_total', $gesamt);
        $this->app->settings->set('subscribers_points', $punkte);
        $this->app->settings->set('subscribers_head', $kopf);
        $this->app->settings->set('subscribers_full_at', time());

        // Der stuendliche Abruf ohne Aenderung meldet nichts.
        if (!$gleich || $stand === 0) {
            $this->app->hooks->dispatch('core.subscribers.changed', $gesamt, $punkte);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $alle nach user_id
     */
    private function store(array $alle): void
    {
        $this->app->db->transaction(function () use ($alle): void {
            $stand = (string) $this->app->db->value('SELECT now()::text');

            foreach ($alle as $id => $zeile) {
                $this->app->db->run(
                    'INSERT INTO twitch_subscribers (user_id, login, name, tier, is_gift, gifter_name, seen_at)
                     VALUES (:id, :login, :name, :tier, CAST(:gift AS boolean), :gifter, CAST(:stand AS timestamptz))
                     ON CONFLICT (user_id) DO UPDATE
                        SET login = EXCLUDED.login, name = EXCLUDED.name, tier = EXCLUDED.tier,
                            is_gift = EXCLUDED.is_gift, gifter_name = EXCLUDED.gifter_name,
                            seen_at = EXCLUDED.seen_at',
                    [
                        'id'     => (string) $id,
                        'login'  => (string) ($zeile['user_login'] ?? ''),
                        'name'   => (string) ($zeile['user_name'] ?? $zeile['user_login'] ?? ''),
                        'tier'   => (string) ($zeile['tier'] ?? '1000'),
                        'gift'   => !empty($zeile['is_gift']) ? 'true' : 'false',
                        'gifter' => (string) ($zeile['gifter_name'] ?? ''),
                        'stand'  => $stand,
                    ]
                );
            }

            $this->app->db->run(
                'DELETE FROM twitch_subscribers WHERE seen_at < CAST(:stand AS timestamptz)',
                ['stand' => $stand]
            );
        });
    }

    private function stored(string $schluessel): ?int
    {
        if ($this->app->settings->int('subscribers_full_at', 0) <= 0) {
            return null;
        }

        $wert = $this->app->settings->int($schluessel, -1);

        return $wert >= 0 ? $wert : null;
    }
}
