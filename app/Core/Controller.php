<?php
declare(strict_types=1);
namespace App\Core;
abstract class Controller
{
    public function __construct(protected readonly Application $app) {}
    protected function view(string $name, array $data = [], string $layout = 'layouts/app'): void { View::render($name, $data, $layout); }
    protected function input(string $key, mixed $default = null): mixed { return $_POST[$key] ?? $_GET[$key] ?? $default; }
    protected function validateCsrf(): void
    {
        $sent = (string) ($_POST['csrf_token'] ?? $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $stored = (string) ($_SESSION['csrf_token'] ?? $_SESSION['_token'] ?? '');
        if ($sent === '' || $stored === '' || !hash_equals($stored, $sent)) { http_response_code(419); exit('Invalid or expired request token.'); }
    }
}
