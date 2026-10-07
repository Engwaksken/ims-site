<?php
declare(strict_types=1);
namespace App\Core;

use RuntimeException;

final class LegacyRunner
{
    public function __construct(private readonly Application $app) {}

    public function run(string $relativePath): void
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, '..') || !preg_match('/^[A-Za-z0-9_\/.\-]+\.php$/', $relativePath)) {
            http_response_code(404); View::render('errors/404'); return;
        }
        $base = realpath($this->app->basePath . '/legacy');
        $file = realpath($this->app->basePath . '/legacy/' . $relativePath);
        if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
            http_response_code(404); View::render('errors/404'); return;
        }

        // Preserve old relative includes while all HTTP traffic enters through public/index.php.
        $oldCwd = getcwd();
        $oldScript = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $oldScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        chdir(dirname($file));
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['SCRIPT_NAME'] = '/' . $relativePath;
        $GLOBALS['mvc_app'] = $this->app;
        try { require $file; }
        finally {
            if ($oldCwd !== false) chdir($oldCwd);
            if ($oldScript !== null) $_SERVER['SCRIPT_FILENAME'] = $oldScript;
            if ($oldScriptName !== null) $_SERVER['SCRIPT_NAME'] = $oldScriptName;
        }
    }
}
