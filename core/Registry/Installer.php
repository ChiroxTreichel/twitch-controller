<?php

declare(strict_types=1);

namespace TwitchController\Core\Registry;

use TwitchController\Core\App;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Holt ein Plugin-Paket aus dem Katalog und legt seine Dateien nach
 * plugins/<slug>/.
 *
 * Hier landet fremder Code auf dem Server, deshalb steht zwischen
 * Download und Dateisystem eine Reihe von Pruefungen:
 *
 *   - Die Download-Adresse muss auf demselben Host liegen wie der
 *     Katalog. Ein manipulierter Katalog kann so nicht auf einen
 *     beliebigen Server umleiten.
 *   - Die Groesse ist begrenzt, waehrend des Downloads und beim
 *     Entpacken (gegen absichtlich aufgeblaehte Archive).
 *   - Der SHA-256 aus dem Katalog muss stimmen.
 *   - Liegt eine Signatur vor UND ist ein oeffentlicher Schluessel
 *     hinterlegt, muss auch die stimmen. (Optional - siehe
 *     Einstellung registry_public_key.)
 *   - Kein Eintrag im Archiv darf aus seinem Verzeichnis herausfuehren.
 *   - Das Archiv muss plugin.json und plugin.php im Wurzelverzeichnis
 *     haben, und der Slug darin muss der angeforderte sein.
 *
 * Erst wenn alles davon passt, werden die Dateien an ihren Platz
 * geschoben - vorher liegen sie in einem Nebenordner.
 */
final class Installer
{
    private const MAX_DOWNLOAD_BYTES = 33554432;   // 32 MB
    private const MAX_UNPACKED_BYTES = 134217728;  // 128 MB
    private const MAX_FILES = 3000;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Ist das Plugin-Verzeichnis fuer den Webserver beschreibbar?
     * Ohne das geht keine Installation ueber die Oberflaeche.
     */
    public function canWrite(): bool
    {
        $dir = $this->app->root . '/plugins';

        return is_dir($dir) && is_writable($dir);
    }

    /**
     * Laedt das Paket und legt die Dateien ab. Vorhandene Dateien des
     * Plugins werden ersetzt; Datenbank und Einstellungen bleiben
     * unberuehrt - dafuer ist install.php des Plugins zustaendig.
     *
     * @param array<string, mixed> $package Katalogeintrag
     */
    public function fetch(array $package): void
    {
        $slug = (string) $package['slug'];

        if (!$this->canWrite()) {
            throw new RuntimeException(
                'Das Verzeichnis plugins/ ist für den Webserver nicht beschreibbar. '
                . 'Einmal "sudo ./install.sh" auf dem Server behebt das.'
            );
        }

        $this->assertSameHost((string) $package['download']);

        $archive = $this->download((string) $package['download']);

        try {
            $this->verifyChecksum($archive, (string) $package['sha256']);
            $this->verifySignature($archive, (string) $package['signature']);

            $staging = $this->extractToStaging($archive, $slug);
        } finally {
            @unlink($archive);
        }

        try {
            $this->swapIntoPlace($staging, $slug);
        } catch (Throwable $e) {
            self::removeTree($staging);
            throw $e;
        }
    }

    // -----------------------------------------------------------------
    //  Pruefungen
    // -----------------------------------------------------------------

    /**
     * Der Download muss vom selben Host kommen wie der Katalog. Sonst
     * koennte ein uebernommener Katalog auf fremde Pakete zeigen.
     */
    /**
     * Loescht die Dateien eines Plugins aus plugins/.
     *
     * Das Gegenstueck zu fetch(), nicht zu uninstall(): uninstall()
     * raeumt Datenbank und Einstellungen ab und laesst die Dateien
     * liegen - danach steht das Plugin als "liegt bereit" da und kann
     * ohne neuen Download wieder eingeschaltet werden. Erst diese
     * Methode macht es ganz weg.
     *
     * Registrierte Plugins werden abgelehnt. Waeren die Dateien weg,
     * waehrend in der Datenbank noch Zeilen und Tabellen des Plugins
     * stehen, kaeme niemand mehr an dessen uninstall.php - die Daten
     * blieben fuer immer liegen.
     */
    public function remove(string $slug): void
    {
        $slug = strtolower(trim($slug));

        if (preg_match('/^[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]$/', $slug) !== 1) {
            throw new RuntimeException(translate('market.remove.bad_slug'));
        }

        if ($this->app->plugins->installedVersion($slug) !== null) {
            throw new RuntimeException(translate('market.remove.still_installed'));
        }

        if (!$this->canWrite()) {
            throw new RuntimeException(translate('market.not_writable_short'));
        }

        $wurzel = realpath($this->app->root . '/plugins');
        $ordner = realpath($this->app->root . '/plugins/' . $slug);

        if ($wurzel === false || $ordner === false) {
            throw new RuntimeException(translate('market.remove.not_found', ['slug' => $slug]));
        }

        // Der aufgeloeste Pfad muss unter plugins/ liegen. Faengt einen
        // Symlink ab, der aus dem Verzeichnis herausfuehrt - geloescht
        // wird hier rekursiv, ein Irrtum waere nicht zu widerrufen.
        if (!str_starts_with($ordner, $wurzel . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(translate('market.remove.outside'));
        }

        self::removeTree($ordner);

        if (is_dir($ordner)) {
            throw new RuntimeException(translate('market.remove.failed', ['slug' => $slug]));
        }

        $this->app->hooks->dispatch('plugin.files_removed', $slug);
    }

    private function assertSameHost(string $downloadUrl): void
    {
        $registry = parse_url((new Client($this->app))->baseUrl());
        $download = parse_url($downloadUrl);

        $registryHost = strtolower((string) ($registry['host'] ?? ''));
        $downloadHost = strtolower((string) ($download['host'] ?? ''));

        if ($registryHost === '' || $downloadHost === '' || $registryHost !== $downloadHost) {
            throw new RuntimeException(translate('install.foreign_host', [
                'download' => $downloadHost !== '' ? $downloadHost : '?',
                'catalog'  => $registryHost !== '' ? $registryHost : '?',
            ]));
        }
    }

    private function download(string $url): string
    {
        $target = tempnam(sys_get_temp_dir(), 'ovplug');
        if ($target === false) {
            throw new RuntimeException(translate('install.no_tempfile'));
        }

        $handle = fopen($target, 'wb');
        if ($handle === false) {
            @unlink($target);
            throw new RuntimeException(translate('install.tempfile_unreadable'));
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE           => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Abbrechen, sobald zu viel kommt - ohne das koennte ein
            // Server die Platte volllaufen lassen.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadSize, $downloaded) {
                return $downloaded > self::MAX_DOWNLOAD_BYTES ? 1 : 0;
            },
        ]);

        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok === false) {
            @unlink($target);
            throw new RuntimeException(
                $error !== '' ? 'Download fehlgeschlagen: ' . $error : translate('install.download_aborted')
            );
        }

        if ($status < 200 || $status >= 300) {
            @unlink($target);
            throw new RuntimeException("Download fehlgeschlagen (HTTP {$status}).");
        }

        if ((int) filesize($target) === 0) {
            @unlink($target);
            throw new RuntimeException(translate('install.empty'));
        }

        return $target;
    }

    private function verifyChecksum(string $file, string $expected): void
    {
        if ($expected === '') {
            throw new RuntimeException(
                translate('install.no_checksum')
            );
        }

        $actual = hash_file('sha256', $file);

        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            throw new RuntimeException(
                translate('install.checksum_mismatch')
            );
        }
    }

    /**
     * Optionale Signaturpruefung. Kommt erst zum Tragen, wenn in den
     * Einstellungen ein oeffentlicher Schluessel hinterlegt ist - dann
     * aber verbindlich, auch wenn die Signatur fehlt.
     */
    private function verifySignature(string $file, string $signature): void
    {
        $publicKeyHex = $this->app->settings->string('registry_public_key');
        if ($publicKeyHex === '') {
            return;
        }

        if (!extension_loaded('sodium')) {
            throw new RuntimeException(translate('install.sodium_missing'));
        }

        if ($signature === '') {
            throw new RuntimeException(
                translate('install.unsigned')
            );
        }

        $publicKey = @hex2bin($publicKeyHex);
        $signatureBytes = base64_decode($signature, true);
        $contents = file_get_contents($file);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException(translate('install.bad_key'));
        }

        if ($signatureBytes === false || $contents === false
            || !sodium_crypto_sign_verify_detached($signatureBytes, $contents, $publicKey)
        ) {
            throw new RuntimeException(translate('install.bad_signature'));
        }
    }

    // -----------------------------------------------------------------
    //  Entpacken
    // -----------------------------------------------------------------

    /**
     * Entpackt in einen Nebenordner und prueft dort, ob wirklich das
     * angeforderte Plugin drin ist.
     */
    private function extractToStaging(string $archive, string $slug): string
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException(translate('install.not_zip'));
        }

        if ($zip->numFiles > self::MAX_FILES) {
            $zip->close();
            throw new RuntimeException(translate('install.too_many_files'));
        }

        $unpacked = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                throw new RuntimeException(translate('install.damaged'));
            }

            self::assertSafeName((string) $stat['name']);

            $unpacked += (int) $stat['size'];
            if ($unpacked > self::MAX_UNPACKED_BYTES) {
                $zip->close();
                throw new RuntimeException(translate('install.too_large_unpacked'));
            }
        }

        $staging = $this->app->root . '/plugins/.staging-' . $slug . '-' . bin2hex(random_bytes(4));
        if (!mkdir($staging, 0775, true) && !is_dir($staging)) {
            $zip->close();
            throw new RuntimeException(translate('install.no_workdir'));
        }

        if (!$zip->extractTo($staging)) {
            $zip->close();
            self::removeTree($staging);
            throw new RuntimeException('Entpacken fehlgeschlagen.');
        }
        $zip->close();

        // Manche Packprogramme legen alles in einen Unterordner. Wenn
        // genau ein Ordner drin liegt und darin die plugin.json, gilt der
        // als Wurzel.
        $root = $staging;
        if (!is_file($root . '/plugin.json')) {
            $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
            if (count($entries) === 1 && is_dir($root . '/' . $entries[0])
                && is_file($root . '/' . $entries[0] . '/plugin.json')
            ) {
                $root = $root . '/' . $entries[0];
            }
        }

        if (!is_file($root . '/plugin.json') || !is_file($root . '/plugin.php')) {
            self::removeTree($staging);
            throw new RuntimeException(
                translate('install.not_a_plugin')
            );
        }

        $manifest = json_decode((string) file_get_contents($root . '/plugin.json'), true);
        $packageSlug = is_array($manifest) ? strtolower(trim((string) ($manifest['slug'] ?? ''))) : '';

        if ($packageSlug !== $slug) {
            self::removeTree($staging);
            throw new RuntimeException(sprintf(
                'Das Paket enthält "%s", angefordert war "%s".',
                $packageSlug !== '' ? $packageSlug : '?',
                $slug
            ));
        }

        // Falls in einem Unterordner: Inhalt eine Ebene hochziehen, damit
        // der Ordnerwechsel unten einheitlich ist.
        if ($root !== $staging) {
            $lifted = $staging . '.lifted';
            if (!rename($root, $lifted)) {
                self::removeTree($staging);
                throw new RuntimeException(translate('install.unpack_failed'));
            }
            self::removeTree($staging);
            return $lifted;
        }

        return $staging;
    }

    /**
     * Kein Eintrag darf aus dem Zielverzeichnis herausfuehren.
     */
    private static function assertSafeName(string $name): void
    {
        $normalized = str_replace('\\', '/', $name);

        if ($normalized === ''
            || str_starts_with($normalized, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $normalized) === 1
            || preg_match('#^[a-zA-Z]:#', $normalized) === 1
        ) {
            throw new RuntimeException(translate('install.bad_path', ['path' => $name]));
        }
    }

    /**
     * Alte Dateien beiseite schieben, neue an ihren Platz, alte weg.
     * Geht etwas schief, wird der alte Stand zurueckgeholt.
     */
    private function swapIntoPlace(string $staging, string $slug): void
    {
        $target = $this->app->root . '/plugins/' . $slug;
        $backup = $target . '.alt-' . bin2hex(random_bytes(4));

        if (is_dir($target) && !rename($target, $backup)) {
            throw new RuntimeException(translate('install.backup_failed'));
        }

        if (!rename($staging, $target)) {
            if (is_dir($backup)) {
                @rename($backup, $target);
            }
            throw new RuntimeException(translate('install.move_failed'));
        }

        if (is_dir($backup)) {
            self::removeTree($backup);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $full = $path . '/' . $entry;
            if (is_dir($full) && !is_link($full)) {
                self::removeTree($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
