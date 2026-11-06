<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [App\Http\Middleware\SetLocale::class]);
        $middleware->alias(['admin' => App\Http\Middleware\RequireAdmin::class, 'workspace' => App\Http\Middleware\ActivateWorkspace::class, 'csat' => App\Http\Middleware\RequireCsatManager::class]);
        // Scope data before route model binding resolves IDs from the URL.
        $middleware->prependToPriorityList(Illuminate\Routing\Middleware\SubstituteBindings::class, App\Http\Middleware\ActivateWorkspace::class);
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
