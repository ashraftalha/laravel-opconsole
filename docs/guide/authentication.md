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

### Effective permission matrix

These are the package's HTTP checks with the default Gate names. All browser routes require an authenticated user from `authentication.guard`; the view Gate is checked only on the dashboard and fallback asset routes, not as a prerequisite on every operation. Additional host middleware in `route.middleware` can impose further requirements. Session and CSRF middleware remain mandatory on browser routes; see [Routes & Endpoints](/reference/routes).

| Operation | View Gate | Global Run Gate | Operation-Specific Gate |
|---|---|---|---|
| Dashboard, log tail, dashboard health/history, fallback assets | Required | No | No |
| Allow-listed Artisan actions (`clear`, `optimize`, `migrate`, `storage_link`, `security_scan`, `octane_reload`) | No | Required only when the action is unmapped | When mapped, replaces the run Gate |
| Failed-job retry | No | Required at the retry route | `queue_retry`, when mapped, is also required in the controller |
| Log download | Only through the default download Gate fallback | No | `download_gate` required |
| Log deletion | No | Only through the default delete Gate fallback | `delete_gate` required |
| Maintenance mode (`down`, `up`) | No | Required only when the action is unmapped | When mapped, replaces the run Gate |
| Monitoring (`GET {prefix}/health`) | No | No | No Laravel Gate; requires enabled endpoint and matching bearer token |

The default download/delete Gates delegate to view/run only if the host has not defined them. Explicit download/delete Gates can authorize independently. `action_gates` does not control log routes. Grant view access separately when an operator needs the dashboard or the redirect destination after an action.

Authorization does not enable a disabled feature: actions also require `actions.<key>`; retries require both `failed_jobs.enabled` and `failed_jobs.allow_retry`; log routes require `logs.allow_download` or `logs.allow_delete`. Runtime, audit, and execution safety checks still apply. The monitoring endpoint requires `health_endpoint.enabled` and a non-empty `health_endpoint.token`, and is outside `route.middleware`.

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

This permits dashboard and log-tail access but denies commands, job retries, full log downloads, and log deletion with the default configuration. Keep `authorization.action_gates` empty for this read-only policy: a mapped Gate for a normal Artisan action can grant execution even when the run Gate denies it. Failed-job retries additionally require the run Gate; see the [permission matrix](/guide/authentication#effective-permission-matrix). Log tails may contain sensitive data, so viewing still requires a trusted operator.

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

For normal `POST {prefix}/{action}` requests, a mapped Gate **replaces** `run_gate`; it is not an additional check. This lets support staff clear caches while migrations and maintenance mode stay with administrators. Merge the following keys into the existing authorization configuration, retaining the view and log Gate settings and the role-based Gate definitions above:

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

Failed-job retries require `run_gate` at the HTTP route **and** the Gate mapped to `queue_retry`, if configured. A retry-specific Gate can narrow run access but cannot grant retries to a user denied by the run Gate. The retry route has `{id}` but no `{action}` parameter, so its run middleware explicitly checks `run_gate`; the controller then checks `queue_retry` (or `run_gate` again when unmapped). An empty-string key in `action_gates` cannot override this route-level check.

Action keys with no entry in `action_gates` fall back to `run_gate`. A key that **is** mapped to a Gate name your application never defines fails closed (`403`) — it does not silently fall back.

Denying `run_gate` alone is not a read-only policy: mapped normal actions and explicit log Gates may still allow operations. Use the [read-only production recipe](/advanced/production-recipes#read-only-production-recommended). The retry button requires both the run Gate and the `queue_retry` action check, matching the endpoint.

::: warning v1.12.0 and earlier
On these versions the retry route looks up an empty action name, so an `action_gates` entry keyed `''` replaces `run_gate` there. The Retry button also checks only `queue_retry`: when it is mapped and allows a user the run Gate denies, the button appears but the endpoint returns `403`. Both are fixed in v1.12.1; until you upgrade, do not map an empty key.
:::

## Least privilege in production

By default, everyone who passes `run_gate` can also run the high-risk `migrate` and `down` actions, and delete logs (which destroys forensic evidence). For a production rollout:

1. Define `deleteOpConsoleLogs` explicitly, separate from `runOpConsoleActions`.
2. Map `migrate` and `down` to a narrower Gate in `action_gates`.
3. Consider the [Read-only diagnostic preset](/reference/configuration#_2-read-only-diagnostic-preset) for an initial, conservative rollout.

See [Permissions & Security Policies](/advanced/permissions-security-policies) for the complete security posture, and [Security Model](/reference/security) for the full threat model.
