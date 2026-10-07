<?php
declare(strict_types=1);
namespace App\Core;
final class View {public static function render(string $view,array $data=[],string $layout='layouts/app'):void{extract($data,EXTR_SKIP);$base=BASE_PATH.'/app/Views/';$file=$base.$view.'.php';if(!is_file($file))throw new \RuntimeException("View not found: $view");ob_start();require $file;$content=(string)ob_get_clean();if($layout===''){echo $content;return;}require $base.$layout.'.php';}}
