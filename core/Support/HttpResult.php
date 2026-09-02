<?php

declare(strict_types=1);

namespace Overlays\Core\Support;

/**
 * Ergebnis eines HTTP-Aufrufs. Wirft nicht bei Statuscodes - der
 * Aufrufer entscheidet, ob ein 404 ein Fehler ist oder eine Antwort.
 */
final class HttpResult
{
    /**
     * @param array<string, mixed> $json
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $json,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Fehlermeldung, wie Twitch sie liefert - sonst der Rohtext.
     */
    public function error(): string
    {
        $message = $this->json['message'] ?? null;

        return is_string($message) && $message !== '' ? $message : $this->body;
    }
}
