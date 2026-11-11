---
title: Production Configuration Recipes
description: "Copyable OpConsole configurations for read-only production access, limited maintenance access, and health monitoring."
# The pager follows this page's first sidebar entry ("Read-only production", a #hash link).
# The static render cannot match a hash, so pin those neighbours explicitly.
prev:
  text: 5-minute setup
  link: /guide/quick-start
next:
  text: Health Dashboard
  link: /features/health-dashboard
---

# Production Configuration Recipes

Start with **[read-only production](#read-only-production-recommended)**. Enable maintenance actions only after verifying access, audit storage, and the hosting runtime. These recipes assume an installed package, a working application login, and the published `config/opconsole.php`.

| Recipe | Who can use it | What it enables |
|---|---|---|
| [Read-only production](#read-only-production-recommended) | One trusted operator | Dashboard, health, history, log tail; no commands, retries, downloads, or deletion |
| [Limited maintenance](#limited-maintenance-administrators) | Separate operator and administrator | Administrator-only cache clear, optimize, and security scans |
| [Health monitoring](#health-monitoring-without-dashboard-access) | A monitor holding a secret token | JSON readiness checks; no dashboard session or execution privileges |

## Read-only production (recommended)

**1. Application Gates.** Import `Gate` at the top of `app/Providers/AppServiceProvider.php`. Place the definitions inside its existing `boot()` method, replacing any previous definitions of these Gates. Replace the example email with a trusted identity.

```php
use Illuminate\Support\Facades\Gate;

// Inside boot():
Gate::define('viewOpConsole', fn ($user) => $user->email === 'operator@example.com');
Gate::define('runOpConsoleActions', fn ($user) => false);
Gate::define('downloadOpConsoleLogs', fn ($user) => false);
Gate::define('deleteOpConsoleLogs', fn ($user) => false);
```

**2. Package configuration.** Replace these entries in the array returned by `config/opconsole.php`; retain the file's other settings. These are PHP configuration entries, not `.env` variables.

```php
'authorization' => [
    'view_gate' => 'viewOpConsole',
    'run_gate' => 'runOpConsoleActions',
    'download_gate' => 'downloadOpConsoleLogs',
    'delete_gate' => 'deleteOpConsoleLogs',
    'action_gates' => [],
],
'actions' => [
    'clear' => false,
    'optimize' => false,
    'migrate' => false,
    'down' => false,
    'up' => false,
    'storage_link' => false,
    'security_scan' => false,
    'octane_reload' => false,
],
'failed_jobs' => [
    'enabled' => true,
    'limit' => 50,
    'allow_retry' => false,
],
```

In the existing `logs` section, set `allow_download` and `allow_delete` to `false`; preserve your configured `path` if it differs from the default:

```php
'logs' => [
    'path' => storage_path('logs'),
    'allow_download' => false,
    'allow_delete' => false,
],
```

Keep `action_gates` empty: a mapped Gate for a normal Artisan action replaces the run Gate and can grant execution even when it denies. Failed-job retries are different: they require the run Gate plus `queue_retry` when mapped. See the [permission matrix](/guide/authentication#effective-permission-matrix). Review any application-wide `Gate::before` administrator bypass as part of your host authorization policy.

**3. Environment.** Add these values to your deployment environment:

```dotenv
OPCONSOLE_ENABLED=true
OPCONSOLE_AUDIT_POLICY=required
OPCONSOLE_HEALTH_ENDPOINT=false
OPCONSOLE_SECURITY_SCHEDULE=false
```

Read-only means operators cannot perform manual mutations through the console. Health checks still probe services, configured heartbeat jobs still run, and existing application schedules remain active. Log tails can contain sensitive data; viewing remains a trusted permission. The Security tab can display an existing scan, but this recipe does not let the viewer trigger one.

**Verify:** the operator sees the dashboard; an unrelated account receives `403`; the Operations tab has no enabled actions; retries, log downloads, and deletion are unavailable. Use the [access decision tree](/reference/troubleshooting#access-and-actions-decision-tree) if those outcomes differ.

## Limited maintenance administrators

Apply the read-only recipe first. Then replace its view/run Gates with these definitions, keeping download and delete denied:

```php
// AppServiceProvider::boot(): replace the example identities.
Gate::define('viewOpConsole', fn ($user) => in_array($user->email, [
    'operator@example.com',
    'admin@example.com',
], true));
Gate::define('runOpConsoleActions', fn ($user) => $user->email === 'admin@example.com');
```

Replace the `actions` entry in `config/opconsole.php`:

```php
'actions' => [
    'clear' => true,
    'optimize' => true,
    'migrate' => false,
    'down' => false,
    'up' => false,
    'storage_link' => false,
    'security_scan' => true,
    'octane_reload' => false,
],
```

Keep `failed_jobs.allow_retry=false`, `action_gates=[]`, and `OPCONSOLE_AUDIT_POLICY=required`. Cache actions require `pcntl` in the web runtime and an isolated atomic lock store. Security scanning requires Composer 2.4+, subprocess support, the host's `composer.json` and `composer.lock`, and access to its advisory repositories. The default lock store suits one server; [multi-server deployments need shared, isolated locks](/advanced/permissions-security-policies#the-action-lock-single-server-vs-distributed).

Do not broaden `run_gate` merely to make a retry-specific Gate work: doing so also permits every enabled unmapped action, and log deletion unless its Gate is explicitly restricted. Keep retries disabled for this maintenance role; see [per-operation permissions](/guide/authentication#per-operation-permissions).

**Verify:** the operator can view but cannot execute; the administrator can run only the three enabled operations. Confirm an audit row is written using a reviewed low-impact operation in staging before production. Migrations, maintenance mode, job retries, and log deletion remain disabled.

## Health monitoring without dashboard access

Keep the read-only policy above, or deny `viewOpConsole` too if you only need a machine-readable endpoint. This endpoint uses its own bearer token rather than the dashboard Gates.

```dotenv
OPCONSOLE_ENABLED=true
OPCONSOLE_HEALTH_ENDPOINT=true
OPCONSOLE_HEALTH_RATE_LIMIT=60
```

Generate a token on a trusted machine, store it in your secret manager, and inject it as `OPCONSOLE_HEALTH_TOKEN` into both the application and the monitor. Do not commit it or put it in a URL:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Verify with the monitor's environment variable set to the real token:

```bash
curl --fail-with-body \
  -H "Authorization: Bearer $OPCONSOLE_HEALTH_TOKEN" \
  https://your-app.example/admin/opconsole/health
```

**Verify:** a missing/wrong token returns `401`, a disabled endpoint or missing configured token returns `404`, and a critical report returns `503` with diagnostic JSON. Use this as readiness, not liveness. Unknown checks do not automatically fail the default report; configure [required checks and unknown policy](/reference/configuration#health-command) for services the monitor must verify.

## Apply and verify configuration changes

Use your normal deployment mechanism to rebuild cached configuration and routes after changing settings:

```bash
php artisan config:cache
php artisan route:cache
```

Reload long-lived application workers through your deployment tooling where applicable. A control-panel task or deployment pipeline can run these commands when SSH is unavailable; if your host cannot run them, resolve that limitation before expecting configuration changes to take effect. See [shared-hosting limitations](/guide/installation#shared-hosting-and-no-ssh-deployments).
