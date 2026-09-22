<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Every route carries its access declaration (App\Http\Zugriff) as a
 * required argument - there is no way to register one without
 * (docs/spec/01-sicherheit.md section 4, issue #19/M3-6).
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function get(string $pattern, Zugriff $zugriff, \Closure $handler): void
    {
        $this->add(HttpMethod::Get, $pattern, $zugriff, $handler);
    }

    public function post(string $pattern, Zugriff $zugriff, \Closure $handler): void
    {
        $this->add(HttpMethod::Post, $pattern, $zugriff, $handler);
    }

    public function add(HttpMethod $method, string $pattern, Zugriff $zugriff, \Closure $handler): void
    {
        $this->routes[] = new Route($method, $pattern, $zugriff, $handler);
    }

    /**
     * All registered routes with their declarations - for the permission
     * matrix test (tests/Http/RoutePermissionMatrixTest.php).
     *
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function match(HttpMethod $method, string $path): RouteMatch
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route->compile(), $path, $matches) !== 1) {
                continue;
            }
            if ($route->method !== $method) {
                if (!in_array($route->method, $allowed, true)) {
                    $allowed[] = $route->method;
                }
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode($value);
                }
            }

            return RouteMatch::matched($route->handler, $params);
        }

        return $allowed === []
            ? RouteMatch::notFound()
            : RouteMatch::methodNotAllowed($allowed);
    }
}
