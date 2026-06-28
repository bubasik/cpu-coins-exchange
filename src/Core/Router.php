<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $key = strtoupper($method) . ' ' . $pattern;
        $this->routes[$key] = $handler;
    }

    public function get(string $p, callable $h): void    { $this->add('GET', $p, $h); }
    public function post(string $p, callable $h): void   { $this->add('POST', $p, $h); }
    public function put(string $p, callable $h): void    { $this->add('PUT', $p, $h); }
    public function delete(string $p, callable $h): void { $this->add('DELETE', $p, $h); }

    public function dispatch(Request $req): void
    {
        $path = rtrim($req->path, '/') ?: '/';
        foreach ($this->routes as $key => $handler) {
            [$method, $pattern] = explode(' ', $key, 2);
            if ($method !== $req->method) continue;

            $regex = $this->compilePattern($pattern);
            if (preg_match($regex, $path, $m)) {
                $params = array_filter($m, fn($k) => !is_int($k), ARRAY_FILTER_USE_KEY);
                try {
                    $handler($req, $params);
                    return;
                } catch (\Throwable $e) {
                    error_log('Route error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    if (Config::get('APP_DEBUG')) {
                        Response::json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
                    } else {
                        Response::error(500, 'Internal Server Error');
                    }
                    return;
                }
            }
        }
        Response::error(404, 'Not Found');
    }

    private function compilePattern(string $pattern): string
    {
        // Convert {param} into named capture groups
        $regex = preg_replace_callback('/\{([a-zA-Z_]\w*)\}/', function($m) {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);
        return '#^' . rtrim($regex, '/') . '/?$#';
    }
}
