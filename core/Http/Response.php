<?php

declare(strict_types=1);

namespace Overlays\Core\Http;

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

        if ($this->body !== '') {
            echo $this->body;
        }
    }
}
