<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller;
use App\Core\LegacyRunner;
use App\Core\ModuleRegistry;

final class SystemController extends Controller
{
    public function home(): void { $this->legacy('index.php'); }
    public function endpoint(string $path): void
    {
        $path = trim($path, '/');
        if ($path === '') { $this->home(); return; }
        $target = ModuleRegistry::resolve($path);
        if ($target === null) { http_response_code(404); $this->view('errors/404', [], ''); return; }
        $this->legacy($target);
    }
    private function legacy(string $file): void { (new LegacyRunner($this->app))->run($file); }
}
