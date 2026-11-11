---
title: Custom Health Checks
---

# Custom Health Checks

OpConsole's only callable extension point is the `HealthCheck` contract. `config/opconsole.php` contains zero closures by design, so `php artisan config:cache` always works — custom checks are registered as class names, resolved from the container.

## The contract

```php
namespace AshrafTalha\OpConsole\Contracts;

interface HealthCheck
{
    /**
     * @return array{group: string, label: string, ok: bool, severity: 'danger'|'warning'|'info', hint: string, value: ?string, fix: ?string, id?: string, status?: 'healthy'|'warning'|'critical'|'unknown'}
     */
    public function run(): array;
}
```

## A worked example

```php
namespace App\HealthChecks;

use AshrafTalha\OpConsole\Contracts\HealthCheck;
use Illuminate\Support\Facades\Storage;

class BackupDiskCheck implements HealthCheck
{
    public function run(): array
    {
        $ok = Storage::disk('local')->exists('backups');

        return [
            'id' => 'backups',                // reported as custom.backups
            'group' => 'Backups',
            'label' => 'Backup folder',
            'ok' => $ok,
            'severity' => 'warning',          // danger, warning or info
            'hint' => 'The backup folder must exist.',
            'value' => $ok ? 'Found' : 'Missing',
            'fix' => $ok ? null : 'Create the backups directory on the configured local disk.',
        ];
    }
}
```

Register it:

```php
// config/opconsole.php
'checks' => [
    App\HealthChecks\BackupDiskCheck::class,
],
```

Because implementations are resolved from the container, constructor dependencies are injected normally — inject a repository, an HTTP client, whatever the check needs.

## The `id` and `status` keys

- `id` gives the check a stable slug, stored and reported as `custom.<slug>`. Without it, OpConsole falls back to a class-derived identifier.
- `status` lets a check report explicitly that it **cannot** verify something, by returning `'status' => 'unknown'` — for example, a dependency on an external service you have no reliable way to probe synchronously.

## Making an unknown check matter

By default, `unknown` checks stay non-failing for the overall report (`health.unknown_policy` defaults to `healthy`, for compatibility with monitoring integrations that only look at overall status). Two ways to make a specific check matter:

```php
// config/opconsole.php
'health' => [
    'required_checks' => ['custom.backups'], // always critical if unknown
    'unknown_policy' => 'healthy',            // or 'warning' / 'critical' globally
],
```

Add the check's `custom.<id>` string to `required_checks` to make it always critical when unknown — including when the check itself throws. A required custom check that throws is reported as a critical `Required check …` entry rather than disappearing from the report silently.

## Where custom checks appear

Everywhere built-in checks do: the Health tab, the Overview summary, the `opconsole:health` CLI command, and the JSON health endpoint. They follow the same severity ordering and grouping as built-in checks.

## Rules enforced by the framework

- Duplicate custom check classes in `config('opconsole.checks')` are deduplicated.
- A custom check attempting to reuse a built-in `(group, label)` pair is skipped with a warning logged, rather than silently shadowing a built-in check.
- `HealthChecks` validates the scalar types and shape of what `run()` returns — malformed output doesn't propagate into the UI unpredictably.

## Practical guidance

- **Keep checks fast.** Every dashboard load, CLI run, and health-endpoint request runs every check synchronously, including yours. OpConsole cannot interrupt a slow check.
- **Bound your own network calls.** If a check makes an HTTP request, give it an explicit short timeout — don't rely on PHP's default.
- **Don't return secrets in `value`.** Like built-in checks, custom check output is rendered directly in the UI (escaped, but visible to anyone with `viewOpConsole`).

See also [Extension Points & Contracts](/advanced/extension-points) for the broader picture of what is and isn't extensible.
