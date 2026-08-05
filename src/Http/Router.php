<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal route table -- pattern to handler, with {placeholder} segments.
 *
 * Replaces the chain of ifs in public/index.php that indexed $uri[1]/$uri[2]
 * without checking they existed and could only ever express two routes.
 */
class Router
{
    /** @var list<array{method: string, regex: string, params: list<string>, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): self
    {
        $params = [];
        $regex  = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $pattern
        );

        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];

        return $this;
    }

    public function get(string $p, callable $h): self    { return $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): self   { return $this->add('POST', $p, $h); }
    public function patch(string $p, callable $h): self  { return $this->add('PATCH', $p, $h); }
    public function delete(string $p, callable $h): self { return $this->add('DELETE', $p, $h); }
    public function put(string $p, callable $h): self    { return $this->add('PUT', $p, $h); }

    /**
     * @return array{status: int, handler: ?callable, params: array<string, string>,
     *               allowed: list<string>, pattern: ?string}
     *         status is 200 on a match, 405 when the path matches but the method
     *         does not, 404 otherwise. `pattern` is the declared route template
     *         (e.g. '/api/robots/{id}'), which lets the caller look the route up
     *         in an auth allow-list without re-parsing the path. Returned rather
     *         than dispatched so matching stays unit-testable.
     */
    public function match(string $method, string $path): array
    {
        $method  = strtoupper($method);
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $m) !== 1) {
                continue;
            }

            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            array_shift($m); // drop the full match
            return [
                'status'  => 200,
                'handler' => $route['handler'],
                'params'  => array_combine($route['params'], $m) ?: [],
                'allowed' => [],
                'pattern' => $route['pattern'],
            ];
        }

        return [
            'status'  => $allowed === [] ? 404 : 405,
            'handler' => null,
            'params'  => [],
            'allowed' => array_values(array_unique($allowed)),
            'pattern' => null,
        ];
    }
}
