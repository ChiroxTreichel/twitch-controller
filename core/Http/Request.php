<?php

declare(strict_types=1);

namespace Overlays\Core\Http;

/**
 * Eingehender Request. Alles laeuft ueber public/index.php, also gibt es
 * genau eine Stelle, die $_SERVER auswertet.
 */
final class Request
{
    /**
     * @param array<string, string|array<mixed>> $query
     * @param array<string, string|array<mixed>> $post
     * @param array<string, string>              $headers
     */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly string $ip,
        public readonly bool $secure,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = '/' . trim(rawurldecode($path), '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        // Body nur lesen, wenn es keiner der von PHP schon geparsten
        // Formular-Requests ist - sonst ist php://input leer.
        $rawBody = '';
        $contentType = strtolower($headers['content-type'] ?? '');
        $isForm = str_contains($contentType, 'application/x-www-form-urlencoded')
            || str_contains($contentType, 'multipart/form-data');
        if (!$isForm && $method !== 'GET' && $method !== 'HEAD') {
            $rawBody = (string) file_get_contents('php://input');
        }

        return new self(
            method: $method,
            path: $path === '/' ? '/' : rtrim($path, '/'),
            query: $_GET,
            post: $_POST,
            headers: $headers,
            rawBody: $rawBody,
            ip: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            secure: ((string) ($_SERVER['HTTPS'] ?? '')) !== '',
        );
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function input(string $key, string $default = ''): string
    {
        $value = $this->post[$key] ?? null;

        return is_string($value) ? trim($value) : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
