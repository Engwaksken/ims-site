<?php
declare(strict_types=1);
namespace App\Core;

final class Router
{
    private array $routes = [];
    private $fallback = null;

    public function __construct(private readonly Application $app) {}

    public function get(string $uri, array|callable $action, array $middleware = []): void { $this->add('GET', $uri, $action, $middleware); }
    public function post(string $uri, array|callable $action, array $middleware = []): void { $this->add('POST', $uri, $action, $middleware); }
    public function any(string $uri, array|callable $action, array $middleware = []): void { foreach (['GET','POST','PUT','PATCH','DELETE','OPTIONS'] as $method) $this->add($method, $uri, $action, $middleware); }
    public function fallback(array|callable $action): void { $this->fallback = $action; }

    private function add(string $method, string $uri, array|callable $action, array $middleware): void
    {
        $uri = '/' . trim($uri, '/');
        if ($uri === '//') $uri = '/';
        $pattern = preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', static fn(array $m): string => '(?P<' . $m[1] . '>[^/]+)', $uri);
        $this->routes[$method][] = ['uri'=>$uri, 'pattern'=>'#^'.$pattern.'/?$#', 'action'=>$action, 'middleware'=>$middleware];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = '/' . ltrim(rawurldecode($uri), '/');
        foreach ($this->routes[strtoupper($method)] ?? [] as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) continue;
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $this->runMiddleware($route['middleware']);
            $this->invoke($route['action'], $params);
            return;
        }
        if ($this->fallback !== null) { $this->invoke($this->fallback, ['path'=>ltrim($path, '/')]); return; }
        http_response_code(404); View::render('errors/404');
    }

    private function runMiddleware(array $middleware): void
    {
        foreach ($middleware as $mw) {
            if ($mw === 'auth') Auth::requireLogin();
            elseif ($mw === 'guest' && Auth::check()) redirect('/dashboard.php');
            elseif (str_starts_with($mw, 'role:')) Auth::requireRole(array_map('trim', explode(',', substr($mw, 5))));
        }
    }

    private function invoke(array|callable $action, array $params): void
    {
        if (is_callable($action)) { $action($this->app, ...array_values($params)); return; }
        [$class, $method] = $action;
        (new $class($this->app))->{$method}(...array_values($params));
    }
}
