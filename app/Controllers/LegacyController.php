<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Controller; use App\Core\Auth;
final class LegacyController extends Controller {public function page(string $file):void{Auth::requireLogin();$path=BASE_PATH.'/legacy/'.basename($file);if(!is_file($path)){http_response_code(404);exit('Legacy module not found.');}chdir(dirname($path));require $path;}}
