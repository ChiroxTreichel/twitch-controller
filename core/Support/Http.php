<?php

declare(strict_types=1);

namespace Overlays\Core\Support;

use RuntimeException;

/**
 * Schlanker HTTP-Client. Ersetzt die vier verschiedenen curl-Varianten,
 * die im alten Code verstreut waren (twitchGet, twitchPost, twitchRequest,
 * http_json) - jede mit eigenem Fehlerverhalten und eigenem Timeout.
 */
final class Http
{
    /**
     * @param array<string, string> $headers
     */
    public static function get(string $url, array $headers = [], int $timeout = 15): HttpResult
    {
        return self::request('GET', $url, $headers, null, $timeout);
    }

    /**
     * @param array<string, string> $form
     * @param array<string, string> $headers
     */
    public static function form(string $url, array $form, array $headers = [], int $timeout = 15): HttpResult
    {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return self::request('POST', $url, $headers, http_build_query($form), $timeout);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function json(
        string $method,
        string $url,
        array $payload,
        array $headers = [],
        int $timeout = 15
    ): HttpResult {
        $headers['Content-Type'] = 'application/json';

        return self::request(
            $method,
            $url,
            $headers,
            (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $timeout
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 15
    ): HttpResult {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('curl konnte nicht initialisiert werden.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 10),
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException(sprintf('%s %s fehlgeschlagen: %s', strtoupper($method), $url, $error));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = json_decode((string) $raw, true);

        return new HttpResult($status, (string) $raw, is_array($decoded) ? $decoded : []);
    }
}
