<?php

namespace AshrafTalha\OpConsole\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates and then authorizes. $ability is "view", "run", "download", or "delete",
 * matching the four Gates in config('opconsole.authorization'). If the host has not
 * defined the default Gates, the package's own fallback (registered by the service
 * provider only for the default Gate names) applies: allow in "local", deny everywhere else.
 *
 * Deliberately does not delegate the guest check to Laravel's 'auth'
 * middleware: that middleware calls route('login') unconditionally and
 * throws an uncaught RouteNotFoundException (-> HTTP 500) on a host with no
 * route named "login". Here, a missing, unconfigured, or parameterized login
 * destination degrades to a controlled 401 instead.
 */
class AuthorizeOpConsole
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $guardName = config('opconsole.authentication.guard');
        if ($guardName !== null && (! is_string($guardName) || trim($guardName) === '')) {
            abort(403);
        }

        try {
            $guard = Auth::guard($guardName);
        } catch (Throwable) {
            abort(403);
        }

        if (! $guard->check()) {
            return $this->unauthenticated($request);
        }

        if (is_string($guardName)) {
            Auth::shouldUse($guardName);
        }

        abort_unless(self::allows($ability, $guard->user()), 403);

        return $next($request);
    }

    public static function allows(string $ability, ?Authenticatable $user): bool
    {
        $gate = self::gateNameFor($ability);

        if ($gate === null || ! Gate::has($gate)) {
            return false;
        }

        return Gate::forUser($user)->allows($gate);
    }

    public static function gateNameFor(string $ability): ?string
    {
        $authConfig = config('opconsole.authorization');
        if (! is_array($authConfig)) {
            return null;
        }

        $key = $ability.'_gate';
        if (! array_key_exists($key, $authConfig)) {
            // Preserve compatibility with published v0.1.0 configs that only defined view_gate and run_gate.
            return match ($ability) {
                'download' => 'downloadOpConsoleLogs',
                'delete' => 'deleteOpConsoleLogs',
                default => null,
            };
        }

        $value = $authConfig[$key];

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function unauthenticated(Request $request): Response
    {
        $login = config('opconsole.authentication.login_route');

        if (is_string($login) && $login !== '' && Route::has($login) && ! $request->expectsJson()) {
            try {
                return redirect()->guest(route($login));
            } catch (Throwable) {
                // Parameterized login route cannot be generated without arguments; fall through to 401.
            }
        }

        abort(401, 'Authentication required.');
    }
}
