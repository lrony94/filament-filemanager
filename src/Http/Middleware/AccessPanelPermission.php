<?php

namespace Amirhellboy\FilamentFileManager\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
// use Amirhellboy\FilamentTinymceEditor\Models\TinymcePermission;

class AccessPanelPermission
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        // Simplified permission check - just check if user is authenticated
        // You can add more specific permission checks here if needed
        return $next($request);
    }
}
