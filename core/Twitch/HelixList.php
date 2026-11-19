<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

use RuntimeException;

/**
 * Eine Liste bei Twitch abholen, die blaettert - Follower und Abos.
 *
 * Beide Endpunkte liefern hundert Zeilen je Anfrage, die Gesamtzahl
 * dazu und einen Cursor fuer die naechste Seite. Gefragt wird mit dem
 * Token des Kanalinhabers.
 *
 * Gemeinsam fuer Followers und Subscribers, damit beide dieselben
 * Sicherungen haben: Zeitgrenze, Seitengrenze, und nur Zeilen mit
 * user_id.
 *
 * @property-read \TwitchController\Core\App $app
 */
trait HelixList
{
    /**
     * Eine Seite.
     *
     * @return array{data: list<array<string, mixed>>, total: int, cursor: string, json: array<string, mixed>}
     */
    private function helixPage(string $endpunkt, string $kanal, string $cursor): array
    {
        $abfrage = ['broadcaster_id' => $kanal, 'first' => 100];
        if ($cursor !== '') {
            $abfrage['after'] = $cursor;
        }

        $antwort = $this->app->twitch->api()->as(TokenStore::BROADCASTER)->get($endpunkt, $abfrage);
        if (!$antwort->ok()) {
            throw new RuntimeException($endpunkt . ': ' . $antwort->error());
        }

        $json = is_array($antwort->json) ? $antwort->json : [];
        $daten = $json['data'] ?? [];

        return [
            'data'   => array_values(array_filter(is_array($daten) ? $daten : [], 'is_array')),
            'total'  => (int) ($json['total'] ?? 0),
            'cursor' => (string) ($json['pagination']['cursor'] ?? ''),
            'json'   => $json,
        ];
    }

    /**
     * Alle Seiten, beginnend mit der schon geholten ersten.
     *
     * @param array{data: list<array<string, mixed>>, total: int, cursor: string} $erste
     * @return array<string, array<string, mixed>> nach user_id
     */
    private function helixAll(string $endpunkt, string $kanal, array $erste): array
    {
        $alle = [];
        $seite = $erste;
        $start = time();

        // Hundert Seiten zu hundert sind zehntausend Zeilen je Minute
        // Abruf - mehr ist eher ein Fehler als ein Kanal.
        for ($i = 0; $i < 2000; $i++) {
            foreach ($seite['data'] as $zeile) {
                $id = (string) ($zeile['user_id'] ?? '');
                if ($id !== '') {
                    $alle[$id] = $zeile;
                }
            }

            if ($seite['cursor'] === '' || $seite['data'] === []) {
                return $alle;
            }

            // Laeuft im Worker - und der hat noch anderes zu tun.
            if (time() - $start > 60) {
                throw new RuntimeException('Abruf dauerte zu lange - diesmal kein Vergleich.');
            }

            $seite = $this->helixPage($endpunkt, $kanal, $seite['cursor']);
        }

        throw new RuntimeException('Zu viele Seiten - diesmal kein Vergleich.');
    }

    /**
     * Woran sich eine Seite wiedererkennen laesst.
     *
     * @param list<array<string, mixed>> $zeilen
     * @param list<string> $felder
     */
    private static function helixHead(array $zeilen, array $felder): string
    {
        $teile = [];
        foreach ($zeilen as $zeile) {
            $werte = [];
            foreach ($felder as $feld) {
                $werte[] = is_scalar($zeile[$feld] ?? null) ? (string) $zeile[$feld] : '';
            }
            $teile[] = implode('@', $werte);
        }

        return md5(implode(',', $teile));
    }

    /**
     * Passt die geholte Liste zur Gesamtzahl? Waehrend des Blaetterns
     * kann sich etwas aendern - ein, zwei Abweichungen sind normal.
     * Mehr heisst: es fehlt eine Seite.
     */
    private static function helixComplete(int $geholt, int $gesamt): bool
    {
        return abs($geholt - $gesamt) <= max(3, (int) ceil($gesamt * 0.01));
    }
}
