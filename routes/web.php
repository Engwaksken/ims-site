<?php
declare(strict_types=1);
use App\Controllers\SystemController;

$app->router->any('/', [SystemController::class, 'home']);
$app->router->any('/{path}', [SystemController::class, 'endpoint']);
$app->router->fallback([SystemController::class, 'endpoint']);
