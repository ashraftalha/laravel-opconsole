---
title: Authentication & Authorization
---

# Authentication & Authorization

OpConsole never introduces its own login system. It reuses your application's authentication and authorization through standard Laravel Gates.

## How authentication works

`AuthorizeOpConsole` middleware checks authentication directly instead of relying on Laravel's `auth` middleware alias. This is deliberate: the `auth` alias calls `route('login')` unconditionally, which throws an uncaught `RouteNotFoundException` (HTTP 500) on any host without a route named `login`. OpConsole's middleware performs the guest check itself, so:

- A guest with a valid, parameterless `login` route is redirected there.
- A guest on a host where `authentication.login_route` is `null`, the route doesn't exist, or it requires parameters, gets a controlled HTTP `401` instead of a crash.

```php
// config/opconsole.php
'authentication' => [
    'guard' => null,        // null uses Laravel's default guard
    'login_route' => 'login',
],
```

Setting `authentication.guard` to a named guard makes OpConsole authenticate against that guard specifically: it calls `Auth::shouldUse($guardName)`, evaluates `Gate::forUser($guard->user())`, and attributes audit rows in `opconsole_runs` to that guard's user. An invalid guard name fails closed with HTTP `403`.

## How authorization works

OpConsole checks four Gates:

| Gate | Allows | Falls back to |
|---|---|---|
| `viewOpConsole` | Viewing the dashboard, health checks, history, and the log tail | Local-only default |
| `runOpConsoleActions` | Running operations | Local-only default |
| `downloadOpConsoleLogs` | Downloading full raw log files | `viewOpConsole` |
| `deleteOpConsoleLogs` | Deleting rotated log files | `runOpConsoleActions` |

### The fail-closed default

If you never define `viewOpConsole` or `runOpConsoleActions` yourself, OpConsole registers this default:

```php
Gate::define($viewGate, fn ($user = null) => app()->environment('local'));
Gate::define($runGate, fn ($user = null) => app()->environment('local'));
```

This fallback only applies when `view_gate`/`run_gate` keep their default names **and** your application hasn't already defined them. If you configure a *custom* Gate name (for example `'view_gate' => 'canAccessOps'`), you must define that Gate yourself — a custom, undefined Gate name denies access everywhere, including `local`.

### Defining Gates in production

Start with a trusted read-only operator. Import `Gate` at the top of `app/Providers/AppServiceProvider.php`, then define the Gates inside `boot()`:

```php
use Illuminate\Support\Facades\Gate;

// Inside boot(): replace this placeholder with a trusted operator's identity.
Gate::define('viewOpConsole', fn ($user) => $user->email === 'operator@example.com');
Gate::define('runOpConsoleActions', fn ($user) => false);
Gate::define('downloadOpConsoleLogs', fn ($user) => false);
Gate::define('deleteOpConsoleLogs', fn ($user) => false);
```

This permits dashboard and log-tail access but denies commands, job retries, full log downloads, and log deletion with the default configuration. Keep `authorization.action_gates` empty for this read-only policy: a mapped action Gate can independently grant execution. Log tails may contain sensitive data, so viewing still requires a trusted operator.

When administrators need to execute actions, **replace** those definitions with a separate operator/admin policy:

```php
// Inside boot(): replace both placeholders with identities you control.
Gate::define('viewOpConsole', fn ($user) => in_array($user->email, [
    'operator@example.com',
    'admin@example.com',
], true));
Gate::define('runOpConsoleActions', fn ($user) => $user->email === 'admin@example.com');
Gate::define('downloadOpConsoleLogs', fn ($user) => $user->email === 'admin@example.com');
Gate::define('deleteOpConsoleLogs', fn ($user) => false);
```

The operator remains read-only; the administrator can run enabled actions (including migrations and job retries) and download logs. Log deletion stays disabled until explicitly granted. For an existing role or permission system, use equivalent permission checks instead of email addresses.

For example, if your application supplies `hasRole()`, replace the four Gates with these role checks:

```php
Gate::define('viewOpConsole', fn ($user) => $user->hasRole('admin') || $user->hasRole('support'));
Gate::define('runOpConsoleActions', fn ($user) => $user->hasRole('admin'));
Gate::define('downloadOpConsoleLogs', fn ($user) => $user->hasRole('admin'));
Gate::define('deleteOpConsoleLogs', fn ($user) => false);
```

### Per-operation permissions

Map individual action keys to independent Gates so, for example, support staff can clear caches while migrations and maintenance mode stay with administrators:

```php
// config/opconsole.php
'authorization' => [
    'run_gate' => 'runOpConsoleActions', // fallback for unmapped actions
    'action_gates' => [
        'clear' => 'clearOpConsoleCaches',
        'migrate' => 'manageOpConsole',
        'down' => 'manageOpConsole',
        'up' => 'manageOpConsole',
        'queue_retry' => 'retryOpConsoleJobs',
    ],
],
```

```php
Gate::define('clearOpConsoleCaches', fn ($user) => $user->hasRole('support') || $user->hasRole('admin'));
Gate::define('manageOpConsole', fn ($user) => $user->hasRole('admin'));
Gate::define('retryOpConsoleJobs', fn ($user) => $user->hasRole('admin'));
```

Failed-job retries require `run_gate` at the HTTP route **and** the Gate mapped to `queue_retry`, if configured. A retry-specific Gate can narrow run access but cannot grant retries to a user denied by the run Gate.

Action keys with no entry in `action_gates` fall back to `run_gate`. A key that **is** mapped to a Gate name your application never defines fails closed (`403`) — it does not silently fall back.

Users who pass `viewOpConsole` but not `runOpConsoleActions` (or a narrower action Gate) see the console in read-only mode: every disabled button explains why it's disabled.

## Least privilege in production

By default, everyone who passes `run_gate` can also run the high-risk `migrate` and `down` actions, and delete logs (which destroys forensic evidence). For a production rollout:

1. Define `deleteOpConsoleLogs` explicitly, separate from `runOpConsoleActions`.
2. Map `migrate` and `down` to a narrower Gate in `action_gates`.
3. Consider the [Read-only diagnostic preset](/reference/configuration#_2-read-only-diagnostic-preset) for an initial, conservative rollout.

See [Permissions & Security Policies](/advanced/permissions-security-policies) for the complete security posture, and [Security Model](/reference/security) for the full threat model.
