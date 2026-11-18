<?php

use App\Http\Middleware\RejectMalformedJson;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Prepended so it runs before TrimStrings & co. first read the JSON body.
        $middleware->prepend(RejectMalformedJson::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This application only serves JSON; never render HTML error pages.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
