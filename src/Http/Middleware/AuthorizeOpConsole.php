<?php

namespace AshrafTalha\OpConsole\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deny-by-default authorization. $ability is "view" or "run", matching the
 * two Gates in config('opconsole.authorization'). If the host has not
 * defined the Gate, the package's own fallback (registered by the service
 * provider only when missing) applies: allow in "local", deny everywhere else.
 */
class AuthorizeOpConsole
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $gate = config('opconsole.authorization.'.$ability.'_gate');

        abort_unless($gate && Gate::allows($gate), 403);

        return $next($request);
    }
}
