<?php
declare(strict_types=1);
function load_env(string $file): void { if(!is_file($file)) return; foreach(file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$k,$v]=array_map('trim',explode('=',$line,2));$v=trim($v,"\"'");if(getenv($k)===false){putenv("$k=$v");$_ENV[$k]=$v;}}}
function env(string $key,mixed $default=null): mixed {$v=getenv($key);if($v===false)return $default;return match(strtolower($v)){'true'=>(bool)true,'false'=>(bool)false,'null'=>null,default=>$v};}
function e(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path=''): string{return rtrim((string)env('APP_URL',''),'/').'/'.ltrim($path,'/');}
function redirect(string $path): never {header('Location: '.(str_starts_with($path,'http')?$path:url($path)));exit;}
function csrf_token(): string {$t=$_SESSION['csrf_token']??($_SESSION['_token']??'');if(!is_string($t)||strlen($t)<32)$t=bin2hex(random_bytes(32));$_SESSION['csrf_token']=$t;$_SESSION['_token']=$t;return $t;}
function csrf_field(): string{return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">';}
