<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdminExists
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Don't intercept health endpoints, setup routes, assets, root redirect, or authenticated users
        if (
            $request->is('/', 'setup', 'setup/*', 'health', 'up', 'livewire', 'livewire/*', 'build/*', 'storage/*', 'css/*', 'js/*', 'fonts/*') ||
            $request->routeIs('setup', 'setup.store', 'health')
        ) {
            return $next($request);
        }

        if ($request->user()) {
            return $next($request);
        }

        try {
            if (User::where('role', UserRole::SUPER_ADMIN)->doesntExist()) {
                return redirect()->route('setup');
            }
        } catch (\Throwable) {
            // Table doesn't exist yet (e.g., during early setup/migration)
        }

        return $next($request);
    }
}
