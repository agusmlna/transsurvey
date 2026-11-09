<?php

namespace App\Http\Middleware;

use App\Services\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;

/**
 * CSAT templates belong to operation users (and superadmin after picking a
 * workspace). Legacy admin/viewer accounts must not see the new workspace data.
 */
class RequireCsatManager
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_unless(
            $user !== null && in_array($user->role, ['operation', 'superadmin'], true),
            403,
            __('Akses pengelola CSAT diperlukan.')
        );

        $workspaceId = app(CurrentWorkspace::class)->filterId();

        abort_if(
            $workspaceId === null || $workspaceId === CurrentWorkspace::NONE,
            403,
            __('Pilih workspace terlebih dahulu.')
        );

        return $next($request);
    }
}
