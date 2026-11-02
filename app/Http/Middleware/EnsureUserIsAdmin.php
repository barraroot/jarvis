<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to active administrators via the `admin` gate.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            abort(401);
        }

        if (! Gate::forUser($request->user())->allows('admin')) {
            abort(403);
        }

        return $next($request);
    }
}
