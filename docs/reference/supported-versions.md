---
title: Supported Versions
---

# Supported Versions

| Component | Supported |
|---|---|
| PHP | `^8.3` — tested in CI on `8.3`, `8.4`, `8.5` |
| Laravel | `^12.0` or `^13.0` |
| Composer (security audit only) | `2.4+` |
| Current release | `v1.11.0` |

## Verification matrix

The package's CI (`.github/workflows/tests.yml`) runs on every push to `main` and every pull request, across:

- **PHP:** `8.3`, `8.4`, `8.5`
- **Laravel:** `12.*`, `13.*`
- **Dependency resolution:** `prefer-stable` and `prefer-lowest`

Plus a separate **static analysis** job (Larastan level 5, Pint dry-run) on Laravel `12.*` and `13.*`, and a **clean-install smoke test** that installs OpConsole into a fresh `laravel/laravel` application for each of `^12.0` and `^13.0`, publishes config/assets, migrates, caches config/routes/views, and confirms the routes appear in `route:list`.

## Laravel version notes

- **Lock lease renewal** (every ~10 seconds while an action runs) requires Laravel 12.63+ / 13.16+, because older releases' lock drivers can't `refresh()` a lease. On older releases, OpConsole runs safely on the single initial lease, since that lease already outlasts `concurrency.max_runtime_seconds` by design.

## PHP extension notes

- `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, and your database's PDO extension are required, as with any Laravel application.
- `pcntl` is optional but strongly recommended on the web SAPI — without it, browser-triggered timer-bound actions are disabled (only `security_scan` still runs). See [Execution environments](/reference/configuration#execution-environments).

## What "supported" means here

A combination is "supported" if it's in the CI matrix above and currently green. Composer's `composer.json` constraints (`"php": "^8.3"`, `"laravel/framework": "^12.0|^13.0"`) are the source of truth — if you see a mismatch between this page and `composer.json` in the version you're running, trust `composer.json`.

See the [Changelog](/reference/changelog) for what changed release to release, and the [Upgrade Guide](/reference/upgrade) for version-specific migration steps.
