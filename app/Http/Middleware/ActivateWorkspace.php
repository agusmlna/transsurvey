<?php

namespace App\Http\Middleware;

use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;

// Runs before route model binding, so an ID from another workspace in the URL is a 404.
class ActivateWorkspace
{
    public function handle(Request $request, Closure $next)
    {
        app(CurrentWorkspace::class)->activate();
        return $next($request);
    }

    // After the response is sent (streamed downloads included), not when the handler returns.
    public function terminate(Request $request, $response): void
    {
        app(CurrentWorkspace::class)->deactivate();
    }
}
