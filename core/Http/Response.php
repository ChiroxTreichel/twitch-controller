<?php

declare(strict_types=1);

namespace TwitchController\Core\Http;

/**
 * Antwort an den Browser. Wird von Routen zurueckgegeben und erst am Ende
 * von public/index.php ausgeliefert - so kann nichts mitten in der
 * Verarbeitung Header setzen und sich selbst im Weg stehen.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
        /**
         * Schreibt die Antwort selbst, statt einen fertigen Text zu
         * halten. Fuer Antworten, die laufen, waehrend sie gesendet
         * werden - siehe stream().
         */
        private readonly mixed $writer = null,
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            $status,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    /**
     * Antwort, die waehrend des Sendens entsteht.
     *
     * Gedacht fuer Server-Sent Events: der Aufrufer bekommt eine
     * Funktion, die er beliebig lange laufen lassen kann, und schreibt
     * darin mit echo. Die Header sind dann schon raus.
     *
     * Zwei Dinge muss der Schreiber selbst beachten:
     *
     *   - Sich zeitlich begrenzen. Jede offene Antwort belegt einen
     *     PHP-Prozess; laeuft sie ewig, sind irgendwann alle belegt.
     *     Bei SSE verbindet der Browser von selbst neu.
     *   - Mit connection_aborted() aufhoeren, wenn der Browser weg ist.
     *
     * @param callable(): void $writer
     * @param array<string, string> $headers
     */
    public static function stream(callable $writer, array $headers = []): self
    {
        return new self(200, '', $headers + [
            'Content-Type'  => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            // Nginx puffert text/event-stream sonst und nichts kommt an.
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ], $writer);
    }

    public static function noContent(int $status = 204): self
    {
        return new self($status, '', []);
    }

    /**
     * Datei ausliefern - fuer Plugin-Assets und hochgeladene Alert-Medien,
     * die ausserhalb des DocumentRoots liegen.
     */
    public static function file(string $path, string $contentType, int $maxAge = 3600): self
    {
        $body = @file_get_contents($path);
        if ($body === false) {
            return self::text('Not Found', 404);
        }

        return new self($body === '' ? 204 : 200, $body, [
            'Content-Type'   => $contentType,
            'Cache-Control'  => 'public, max-age=' . $maxAge,
            'Content-Length' => (string) strlen($body),
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->body, $headers + $this->headers);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->writer !== null) {
            // Puffer weg, sonst kommt beim Empfaenger erst am Ende
            // etwas an - und bei einer Antwort, die minutenlang
            // laeuft, waere das nie.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            ($this->writer)();

            return;
        }

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
