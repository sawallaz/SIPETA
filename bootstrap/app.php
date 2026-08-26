<?php

use App\Http\Middleware\EnsureSuperAdminExists;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->web(append: [
            EnsureSuperAdminExists::class,
        ]);
        // Exclude Google Drive OAuth callback from CSRF verification.
        // Google's redirect carries its own CSRF protection via the `state` parameter
        // validated in the callback route; exempting here prevents 419 from CSRF token mismatch.
        $middleware->validateCsrfTokens(except: [
            'admin/backup/google/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

if ($storagePath = env('LARAVEL_STORAGE_PATH')) {
    $app->useStoragePath($storagePath);
}

return $app;
