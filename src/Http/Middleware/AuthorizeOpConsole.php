<?php

namespace AshrafTalha\OpConsole\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates and then authorizes. $ability is "view" or "run", matching
 * the two Gates in config('opconsole.authorization'). If the host has not
 * defined the Gate, the package's own fallback (registered by the service
 * provider only when missing) applies: allow in "local", deny everywhere else.
 *
 * Deliberately does not delegate the guest check to Laravel's 'auth'
 * middleware: that middleware calls route('login') unconditionally and
 * throws an uncaught RouteNotFoundException (-> HTTP 500) on a host with no
 * route named "login". Here, a missing or unconfigured login destination
 * degrades to a controlled 401 instead.
 */
class AuthorizeOpConsole
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $guard = Auth::guard(config('opconsole.authentication.guard'));

        if (! $guard->check()) {
            return $this->unauthenticated($request);
        }

        $gate = config('opconsole.authorization.'.$ability.'_gate');

        abort_unless($gate && Gate::allows($gate), 403);

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        $login = config('opconsole.authentication.login_route');

        if ($login && Route::has($login) && ! $request->expectsJson()) {
            return redirect()->guest(route($login));
        }

        abort(401, 'Authentication required.');
    }
}
