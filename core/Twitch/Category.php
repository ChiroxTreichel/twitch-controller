<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

use Throwable;
use TwitchController\Core\App;

/**
 * Die aktuelle Kategorie des Kanals: $app->twitch->category()
 *
 * Gemerkt wird in den Kern-Einstellungen, damit jeder Prozess - Webhook,
 * Worker, Overlay-Leitung - denselben Stand sieht, ohne Twitch zu fragen:
 *
 *   twitch_category_id     ID der Kategorie ("" = keine, z. B. offline)
 *   twitch_category_name   Klarname, nur zur Anzeige
 *   twitch_title           Titel des Streams
 *   twitch_category_at     Unixzeit des letzten Stands
 *
 * Gefuettert wird der Stand von drei Seiten:
 *
 *   channel.update   Twitch meldet jeden Wechsel sofort (WebhookController)
 *   stream.online    beim Start einmal aktiv nachfragen
 *   cron.tick        stuendlich als Absicherung, falls ein Webhook
 *                    verloren geht (bin/worker.php)
 *
 * Wechselt die ID, feuert 'core.category.changed' mit
 * (string $id, string $name, string $previousId). Ein reiner
 * Titelwechsel feuert nicht - wer auf die Kategorie reagiert, soll nicht
 * bei jedem Tippfehler im Titel umschalten.
 */
final class Category
{
    public const KEY_ID    = 'twitch_category_id';
    public const KEY_NAME  = 'twitch_category_name';
    public const KEY_TITLE = 'twitch_title';
    public const KEY_AT    = 'twitch_category_at';

    /** Wie lange refreshIfDue() nach einem Versuch wartet, auch wenn er scheiterte. */
    private const RETRY_SECONDS = 300;

    /** Letzter Versuch von refreshIfDue() in diesem Prozess (Unixzeit). */
    private int $lastTry = 0;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Der gemerkte Stand.
     *
     * Ist noch nie etwas gemerkt worden (updated_at 0), wird einmal bei
     * Twitch nachgefragt. Scheitert das, kommt der leere Stand zurueck -
     * ein Aufrufer soll damit rechnen, dass die Kategorie unbekannt ist.
     *
     * @return array{id: string, name: string, title: string, updated_at: int}
     */
    public function current(): array
    {
        $stand = $this->stored();

        if ($stand['updated_at'] === 0) {
            $this->refresh();
            $stand = $this->stored();
        }

        return $stand;
    }

    /**
     * Neuen Stand merken.
     *
     * Gibt true zurueck, wenn sich die ID geaendert hat - und nur dann
     * feuert der Haken. Doppelt zugestellte Webhooks oder ein Abgleich,
     * der dasselbe findet, bleiben damit still.
     */
    public function remember(string $id, string $name, string $title): bool
    {
        $id = trim($id);
        $vorher = $this->app->settings->string(self::KEY_ID);

        // Der Zeitpunkt wird auch ohne Wechsel geschrieben: er sagt
        // refreshIfDue(), dass der Stand frisch ist.
        $this->app->settings->setMany([
            self::KEY_ID    => $id,
            self::KEY_NAME  => trim($name),
            self::KEY_TITLE => trim($title),
            self::KEY_AT    => time(),
        ]);

        if ($id === $vorher) {
            return false;
        }

        $this->app->hooks->dispatch('core.category.changed', $id, trim($name), $vorher);

        return true;
    }

    /**
     * Bei Twitch nachfragen und merken.
     *
     * Mit dem Token des Kanalinhabers, wie Streaminfo. Fehler werden
     * geschluckt und ins Log geschrieben: der Aufrufer ist ein Webhook
     * oder der Worker, und keiner von beiden soll wegen einer
     * ausgefallenen Nachfrage stehen bleiben.
     *
     * @return bool true, wenn die Kategorie dabei gewechselt hat
     */
    public function refresh(): bool
    {
        $kanalId = $this->app->twitch->broadcasterId();
        if ($kanalId === '') {
            return false;
        }

        try {
            $antwort = $this->app->twitch->api()
                ->as(TokenStore::BROADCASTER)
                ->get('channels', ['broadcaster_id' => $kanalId]);

            if (!$antwort->ok()) {
                $this->app->log('Kategorie: Twitch-Abfrage fehlgeschlagen: ' . $antwort->error());

                return false;
            }

            $zeile = $antwort->json['data'][0] ?? null;
            if (!is_array($zeile)) {
                $this->app->log('Kategorie: Twitch lieferte keine Kanaldaten.');

                return false;
            }

            return $this->remember(
                (string) ($zeile['game_id'] ?? ''),
                (string) ($zeile['game_name'] ?? ''),
                (string) ($zeile['title'] ?? ''),
            );
        } catch (Throwable $e) {
            $this->app->log('Kategorie: Abfrage fehlgeschlagen: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Nachfragen, wenn der letzte Stand aelter als $maxAge Sekunden ist.
     *
     * Fuer den Worker. Ohne Kanal gibt es nichts zu fragen; sonst stuende
     * jede Stunde ein Fehler im Log einer frischen Installation.
     *
     * Scheitert die Nachfrage, bleibt der Stand alt - ohne Sperre fragte
     * der Worker dann in jedem Takt erneut und schriebe jedes Mal ins
     * Log. Darum hoechstens alle RETRY_SECONDS ein Versuch.
     */
    public function refreshIfDue(int $maxAge = 3600): void
    {
        if (!$this->app->twitch->hasChannel()) {
            return;
        }

        $jetzt = time();
        if ($jetzt - $this->app->settings->int(self::KEY_AT) < $maxAge
            || $jetzt - $this->lastTry < self::RETRY_SECONDS
        ) {
            return;
        }

        $this->lastTry = $jetzt;
        $this->refresh();
    }

    /**
     * @return array{id: string, name: string, title: string, updated_at: int}
     */
    private function stored(): array
    {
        $settings = $this->app->settings;

        return [
            'id'         => $settings->string(self::KEY_ID),
            'name'       => $settings->string(self::KEY_NAME),
            'title'      => $settings->string(self::KEY_TITLE),
            'updated_at' => $settings->int(self::KEY_AT),
        ];
    }
}
