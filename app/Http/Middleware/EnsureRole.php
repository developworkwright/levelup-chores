<?php

namespace App\Http\Middleware;

use App\Enums\ProfileRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Route-level role check. Kept in addition to (not instead of) the
     * per-component mount() checks — defense in depth, not either/or.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $profile = $request->user();

        abort_unless($profile && $profile->role === ProfileRole::from($role), 403);

        return $next($request);
    }
}
