<?php

declare(strict_types=1);

namespace TwitchController\Core\Http;

/**
 * Routen von Kern und Plugins.
 *
 * Muster kennen zwei Platzhalter:
 *   /users/{id}      genau ein Segment, landet in $params['id']
 *   /assets/{path*}  Rest des Pfades inkl. Slashes
 *
 * Handler-Signatur:  fn(Request $request, array $params): Response
 *
 * Zugriffsschutz laeuft ueber $options: 'auth' => true verlangt einen
 * eingeloggten Benutzer, 'permission' => 'Alerts.Follow.Edit' zusaetzlich
 * ein Recht. Geprueft wird das vom Guard, den die Anwendung setzt - so
 * weiss der Router nichts von Benutzern und Rechten.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable, options: array<string, mixed>, source: string, pattern: string}> */
    private array $routes = [];

    private string $source = 'core';

    /** @var (callable(array<string, mixed>, Request): ?Response)|null */
    private $guard = null;

    public function withSource(string $source, callable $work): void
    {
        $previous = $this->source;
        $this->source = $source;

        try {
            $work($this);
        } finally {
            $this->source = $previous;
        }
    }

    /**
     * @param callable(array<string, mixed>, Request): ?Response $guard
     */
    public function setGuard(callable $guard): void
    {
        $this->guard = $guard;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function get(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('GET', $pattern, $handler, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function post(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('POST', $pattern, $handler, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function any(string $pattern, callable $handler, array $options = []): void
    {
        $this->add('*', $pattern, $handler, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function add(string $method, string $pattern, callable $handler, array $options = []): void
    {
        [$regex, $names] = self::compile($pattern);

        $this->routes[] = [
            'method'  => strtoupper($method),
            'regex'   => $regex,
            'names'   => $names,
            'handler' => $handler,
            'options' => $options,
            'source'  => $this->source,
            'pattern' => $pattern,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;
            if ($route['method'] !== '*' && $route['method'] !== $request->method) {
                continue;
            }

            $params = [];
            foreach ($route['names'] as $index => $name) {
                $params[$name] = rawurldecode($matches[$index + 1] ?? '');
            }

            if ($this->guard !== null) {
                $blocked = ($this->guard)($route['options'], $request);
                if ($blocked instanceof Response) {
                    return $blocked;
                }
            }

            return ($route['handler'])($request, $params);
        }

        if ($pathMatched) {
            return Response::text('Method Not Allowed', 405);
        }

        return Response::text('Not Found', 404);
    }

    /**
     * Uebersicht fuer die Adminoberflaeche.
     *
     * @return list<array{method: string, pattern: string, source: string}>
     */
    public function map(): array
    {
        return array_map(
            static fn (array $route): array => [
                'method'  => $route['method'],
                'pattern' => $route['pattern'],
                'source'  => $route['source'],
            ],
            $this->routes
        );
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function compile(string $pattern): array
    {
        $names = [];
        $regex = '';
        $offset = 0;

        $found = preg_match_all(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(\*?)\}/',
            $pattern,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        if ($found > 0) {
            foreach ($matches as $match) {
                $start = (int) $match[0][1];
                // Literaler Teil vor dem Platzhalter muss escaped werden,
                // sonst waere z.B. der Punkt in /favicon.ico ein Joker.
                $regex .= preg_quote(substr($pattern, $offset, $start - $offset), '#');
                $names[] = (string) $match[1][0];
                $regex .= ((string) $match[2][0]) === '*' ? '(.+)' : '([^/]+)';
                $offset = $start + strlen((string) $match[0][0]);
            }
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');

        return ['#^' . $regex . '$#', $names];
    }
}
