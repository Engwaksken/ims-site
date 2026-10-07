<?php

declare(strict_types=1);

use App\Core\Application;

define(
    'BASE_PATH',
    dirname(__DIR__)
);

/*
|--------------------------------------------------------------------------
| Core Helpers
|--------------------------------------------------------------------------
*/

$helpers =
    BASE_PATH
    . '/app/Core/helpers.php';

if (!is_file($helpers)) {
    http_response_code(500);

    die(
        'Application helpers file is missing: '
        . htmlspecialchars(
            $helpers,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

require_once $helpers;


/*
|--------------------------------------------------------------------------
| Environment
|--------------------------------------------------------------------------
*/

if (function_exists('load_env')) {
    load_env(
        BASE_PATH
        . '/.env'
    );
}


/*
|--------------------------------------------------------------------------
| Composer Autoloader
|--------------------------------------------------------------------------
*/

$composerAutoload =
    BASE_PATH
    . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}


/*
|--------------------------------------------------------------------------
| Application Fallback Autoloader
|--------------------------------------------------------------------------
|
| Register this even when Composer exists.
|
| This protects the IMS application if Composer's generated autoloader is
| temporarily incomplete or has not yet been regenerated.
|
*/

spl_autoload_register(
    static function (
        string $class
    ): void {

        $prefix =
            'App\\';

        if (
            !str_starts_with(
                $class,
                $prefix
            )
        ) {
            return;
        }

        $relativeClass =
            substr(
                $class,
                strlen($prefix)
            );

        if ($relativeClass === false) {
            return;
        }

        $relativePath =
            str_replace(
                '\\',
                DIRECTORY_SEPARATOR,
                $relativeClass
            );

        $file =
            BASE_PATH
            . '/app/'
            . $relativePath
            . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    },
    true,
    true
);


/*
|--------------------------------------------------------------------------
| Verify Main Application Class
|--------------------------------------------------------------------------
*/

if (
    !class_exists(
        Application::class
    )
) {
    $expectedFile =
        BASE_PATH
        . '/app/Core/Application.php';

    http_response_code(500);

    die(
        '<h2>IMS bootstrap error</h2>'
        . '<p>Class <strong>'
        . htmlspecialchars(
            Application::class,
            ENT_QUOTES,
            'UTF-8'
        )
        . '</strong> could not be loaded.</p>'
        . '<p>Expected file:</p>'
        . '<pre>'
        . htmlspecialchars(
            $expectedFile,
            ENT_QUOTES,
            'UTF-8'
        )
        . '</pre>'
        . '<p>File exists: '
        . (
            is_file($expectedFile)
                ? 'Yes'
                : 'No'
        )
        . '</p>'
    );
}


/*
|--------------------------------------------------------------------------
| Start Application
|--------------------------------------------------------------------------
*/

$app =
    new Application(
        BASE_PATH
    );


/*
|--------------------------------------------------------------------------
| Routes
|--------------------------------------------------------------------------
*/

$routes =
    BASE_PATH
    . '/routes/web.php';

if (!is_file($routes)) {
    http_response_code(500);

    die(
        'Routes file is missing: '
        . htmlspecialchars(
            $routes,
            ENT_QUOTES,
            'UTF-8'
        )
    );
}

require $routes;


/*
|--------------------------------------------------------------------------
| Run
|--------------------------------------------------------------------------
*/

$app->run();