---
title: Requirements & Compatibility
description: "Laravel OpConsole requirements: PHP 8.3 or newer, Laravel 12 or 13, and Composer 2.4+ for the security audit, plus optional pcntl for timed operations."
---

# Requirements & Compatibility

For shared hosting or no-SSH deployments, check the [installation capability checklist](/guide/installation#shared-hosting-and-no-ssh-deployments) before assuming browser actions can replace deployment tooling.

## Core requirements

| Requirement | Supported |
|---|---|
| PHP | `^8.3` — tested in CI on `8.3`, `8.4`, and `8.5` |
| Laravel Framework | `^12.0` or `^13.0` |
| Composer | 2.4+ (only needed for the [security audit](/features/security-checks)) |

These come from `composer.json`:

```json
"require": {
    "php": "^8.3",
    "laravel/framework": "^12.0|^13.0",
    "symfony/process": "^7.2|^8.0"
}
```

## Optional capabilities and their requirements

| Capability | Requirement | Behavior if missing |
|---|---|---|
| Browser-triggered Artisan actions (clear caches, optimize, migrate, maintenance mode, storage link) | `pcntl` functions available to the **web** SAPI (PHP-FPM / mod_php) | Many PHP-FPM builds disable `pcntl` for the web SAPI by default. Without it, these actions are disabled in the UI with an explanation; only the self-bounded `security_scan` operation still runs from the browser. Run the equivalent `php artisan` command from the CLI instead. See [Execution environments](/reference/configuration#execution-environments). |
| Security audit (`opconsole:security`) | Composer 2.4+ and a `composer.lock` in the host application | The audit command and the Security tab report a clear failure instead of partial or fabricated results. |
| Octane and Swoole checks | [Laravel Octane](https://laravel.com/docs/octane) installed | When Octane isn't installed, OpConsole adds nothing — no extra health group, no System card, no reload action. See [Octane & Swoole](/features/octane-swoole). |
| Queue worker heartbeat | A queue driver other than `sync`/`null`, and a running worker | With `sync`/`null`, the check is skipped with an informational status rather than reported as unhealthy. |
| Scheduler heartbeat | The Laravel scheduler (`schedule:run`) actually running every minute | Without it, the check reads `unknown` until the first beat. |

## CI verification matrix

The package's own GitHub Actions workflow (`.github/workflows/tests.yml`) runs the full test suite and static analysis across:

- **PHP:** `8.3`, `8.4`, `8.5`
- **Laravel:** `12.*`, `13.*`
- **Dependency resolution:** `prefer-stable`, `prefer-lowest`

It also runs a **clean-install smoke test**: a fresh `laravel/laravel` application (built separately for Laravel `^12.0` and `^13.0`) installs OpConsole via a path repository, publishes config and assets, migrates, caches config/routes/views, and confirms the routes are registered — the same steps a real installation goes through.

## Platform notes

- **Windows:** `pcntl` does not exist on Windows PHP builds, so browser-triggered timer-bound actions behave the same as PHP-FPM without `pcntl` — only `security_scan` runs from the browser.
- **Multi-server deployments:** the default file-based action lock only coordinates requests on a single server sharing the same filesystem. See [Permissions & Security Policies](/advanced/permissions-security-policies) for configuring a shared Redis or database lock store.
