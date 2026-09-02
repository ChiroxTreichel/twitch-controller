<?php

declare(strict_types=1);

namespace Overlays\Core\Update;

use Overlays\Core\App;
use Throwable;

/**
 * Updates aus der Oberflaeche.
 *
 * Warum zweistufig: der Webserver antwortet als Benutzer www-data und
 * darf im Projektordner (der root gehoert) nicht schreiben. Der
 * worker-Container laeuft dagegen als root. Also:
 *
 *   1. Die Oberflaeche merkt sich "Update gewuenscht" in der Datenbank.
 *   2. Der Worker sieht das beim naechsten Takt, holt den neuen Stand,
 *      zieht das Schema nach und beendet sich - Docker startet ihn neu,
 *      damit er die neuen Plugin-Dateien laedt.
 *   3. Die Oberflaeche zeigt das Ergebnis an.
 *
 * Was hier NICHT geht: Images neu bauen und Container neu starten. Dazu
 * braeuchte der Webserver Zugriff auf den Docker-Socket, und damit haette
 * jeder, der die Oberflaeche uebernimmt, den ganzen Server. Aendert ein
 * Update etwas an docker/ oder der Compose-Datei, sagt die Oberflaeche
 * deshalb, dass einmal "sudo ./install.sh" auf dem Server noetig ist.
 */
final class Updater
{
    /**
     * Dateien, deren Aenderung einen Neustart oder Neubau braucht - das
     * kann nur ein Mensch auf dem Server anstossen.
     *
     * @var list<string>
     */
    private const SHELL_PATHS = [
        'docker/',
        'docker-compose.yaml',
        'docker-compose.npm.yaml',
        'install.sh',
    ];

    public function __construct(private readonly App $app)
    {
    }

    // -----------------------------------------------------------------
    //  Zustand
    // -----------------------------------------------------------------

    public function isGitCheckout(): bool
    {
        return is_dir($this->app->root . '/.git');
    }

    public function gitAvailable(): bool
    {
        [$ok] = $this->git(['--version']);

        return $ok;
    }

    /**
     * Kurzfassung des installierten Stands, z.B. "1.0.0 (a1b2c3d, 02.09.2026)".
     */
    public function currentVersion(): string
    {
        $parts = [App::VERSION];

        [$ok, $out] = $this->git(['rev-parse', '--short', 'HEAD']);
        if ($ok && $out !== '') {
            $details = $out;

            [$dateOk, $date] = $this->git(['log', '-1', '--format=%cd', '--date=format:%d.%m.%Y']);
            if ($dateOk && $date !== '') {
                $details .= ', ' . $date;
            }

            $parts[] = '(' . $details . ')';
        }

        return implode(' ', $parts);
    }

    /**
     * Letzter bekannter Stand, ohne ins Netz zu gehen.
     *
     * @return array{
     *     checked_at: int, available: bool, behind: int, subject: string,
     *     needs_shell: bool, requested_at: int, last_result: array<string, mixed>
     * }
     */
    public function status(): array
    {
        return [
            'checked_at'   => $this->app->settings->int('update_checked_at', 0),
            'available'    => $this->app->settings->bool('update_available', false),
            'behind'       => $this->app->settings->int('update_behind', 0),
            'subject'      => $this->app->settings->string('update_subject'),
            'needs_shell'  => $this->app->settings->bool('update_needs_shell', false),
            'requested_at' => $this->app->settings->int('update_requested_at', 0),
            'last_result'  => (array) $this->app->settings->get('update_last_result', []),
        ];
    }

    // -----------------------------------------------------------------
    //  Nachsehen
    // -----------------------------------------------------------------

    /**
     * Fragt bei GitHub nach, ob es etwas Neueres gibt, und merkt sich das
     * Ergebnis. Braucht Netz, aendert aber nichts am Code.
     *
     * @return array{ok: bool, message: string}
     */
    public function check(): array
    {
        if (!$this->isGitCheckout()) {
            return [
                'ok' => false,
                'message' => 'Diese Installation ist keine Git-Kopie. '
                    . 'Updates müssen dann von Hand eingespielt werden.',
            ];
        }

        if (!$this->gitAvailable()) {
            return [
                'ok' => false,
                'message' => 'Im Container fehlt das Programm git. '
                    . 'Einmal "sudo ./install.sh" auf dem Server behebt das.',
            ];
        }

        $branch = $this->trackedRef();

        [$fetchOk, $fetchOut] = $this->git(['fetch', '--quiet', 'origin', $branch]);
        if (!$fetchOk) {
            return [
                'ok' => false,
                'message' => 'Konnte GitHub nicht erreichen: ' . $fetchOut,
            ];
        }

        [, $behindRaw] = $this->git(['rev-list', '--count', 'HEAD..FETCH_HEAD']);
        $behind = (int) $behindRaw;

        [, $subject] = $this->git(['log', '-1', '--format=%s', 'FETCH_HEAD']);
        [, $changed] = $this->git(['diff', '--name-only', 'HEAD', 'FETCH_HEAD']);

        $needsShell = false;
        foreach (explode("\n", $changed) as $file) {
            $file = trim($file);
            if ($file === '') {
                continue;
            }
            foreach (self::SHELL_PATHS as $path) {
                if (str_starts_with($file, $path)) {
                    $needsShell = true;
                    break 2;
                }
            }
        }

        $this->app->settings->setMany([
            'update_checked_at'  => time(),
            'update_available'   => $behind > 0,
            'update_behind'      => $behind,
            'update_subject'     => $behind > 0 ? $subject : '',
            'update_needs_shell' => $behind > 0 && $needsShell,
        ]);

        return [
            'ok' => true,
            'message' => $behind > 0
                ? sprintf('Es gibt %d neue Änderung(en).', $behind)
                : 'Alles auf dem neuesten Stand.',
        ];
    }

    // -----------------------------------------------------------------
    //  Beauftragen und ausfuehren
    // -----------------------------------------------------------------

    /**
     * Wird von der Oberflaeche aufgerufen: hinterlegt den Auftrag, den der
     * Worker ausfuehrt.
     */
    public function request(): void
    {
        $this->app->settings->set('update_requested_at', time());
    }

    public function isRequested(): bool
    {
        return $this->app->settings->int('update_requested_at', 0) > 0;
    }

    /**
     * Wird im Worker aufgerufen. Gibt true zurueck, wenn der Prozess sich
     * danach beenden soll, damit Docker ihn mit dem neuen Code neu startet.
     */
    public function applyIfRequested(): bool
    {
        if (!$this->isRequested()) {
            return false;
        }

        $this->app->settings->forget('update_requested_at');

        [$fromOk, $from] = $this->git(['rev-parse', '--short', 'HEAD']);
        $result = ['at' => date('c'), 'ok' => false, 'message' => '', 'from' => $fromOk ? $from : '?'];

        try {
            $branch = $this->trackedRef();

            [$fetchOk, $fetchOut] = $this->git(['fetch', '--quiet', 'origin', $branch]);
            if (!$fetchOk) {
                throw new \RuntimeException('GitHub nicht erreichbar: ' . $fetchOut);
            }

            // Nur vorspulen. Gibt es lokale Abweichungen, wird nichts
            // ueberschrieben - dann muss ein Mensch ran.
            [$mergeOk, $mergeOut] = $this->git(['merge', '--ff-only', 'FETCH_HEAD']);
            if (!$mergeOk) {
                throw new \RuntimeException(
                    'Der Ordner lässt sich nicht einfach vorspulen (eigene Änderungen?): ' . $mergeOut
                );
            }

            [, $to] = $this->git(['rev-parse', '--short', 'HEAD']);
            $result['to'] = $to;

            // Schema des Kerns und der Plugins nachziehen.
            $this->app->settings->flush();
            $this->app->installCore();

            foreach ($this->app->plugins->discover(true) as $manifest) {
                if ($this->app->plugins->isInstalled($manifest->slug)) {
                    $this->app->plugins->upgradeIfNeeded($manifest);
                }
            }

            $result['ok'] = true;
            $result['message'] = 'Update eingespielt.';
        } catch (Throwable $e) {
            $result['message'] = $e->getMessage();
        }

        $this->app->settings->set('update_last_result', $result);
        $this->app->settings->setMany([
            'update_available'  => false,
            'update_behind'     => 0,
            'update_subject'    => '',
            'update_checked_at' => time(),
        ]);

        $this->app->log('Update: ' . ($result['ok'] ? 'erfolgreich' : 'fehlgeschlagen')
            . ' - ' . $result['message']);

        return $result['ok'];
    }

    // -----------------------------------------------------------------

    /**
     * Auf welchen Zweig zeigt diese Kopie? Nach einem "git checkout
     * --detach" gibt es keinen - dann nehmen wir den Standardzweig.
     */
    private function trackedRef(): string
    {
        [$ok, $branch] = $this->git(['rev-parse', '--abbrev-ref', 'HEAD']);

        if ($ok && $branch !== '' && $branch !== 'HEAD') {
            return $branch;
        }

        return 'main';
    }

    /**
     * git im Projektordner ausfuehren.
     *
     * safe.directory ist noetig, weil der Ordner root gehoert und git
     * sonst die Zusammenarbeit verweigert ("dubious ownership").
     *
     * @param list<string> $args
     * @return array{0: bool, 1: string}
     */
    private function git(array $args): array
    {
        $command = 'git -c safe.directory=' . escapeshellarg($this->app->root)
            . ' -C ' . escapeshellarg($this->app->root);

        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        $output = [];
        $status = 1;
        @exec($command . ' 2>&1', $output, $status);

        return [$status === 0, trim(implode("\n", $output))];
    }
}
