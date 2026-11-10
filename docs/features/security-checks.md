---
title: Security Checks
description: "OpConsole audits Composer dependencies for known advisories and checks Laravel runtime configuration for risky settings such as debug mode and insecure cookies."
---

# Security Checks

## What it does

OpConsole combines two kinds of security coverage:

1. **Host dependency audit:** scans your locked Composer packages — including transitive dependencies — against known advisories.
2. **Runtime configuration checks:** Laravel configuration warnings for app key presence, debug mode, session cookie flags, HTTPS enforcement, and OpConsole's own authorization/action configuration.

The dashboard **only reads the last stored report** for the dependency audit — no dashboard page load ever launches Composer.

## Why it is useful

Dependency vulnerabilities and misconfigured production settings (debug mode left on, insecure session cookies) are two of the most common real-world security incidents in PHP applications, and both are easy to overlook without an explicit check.

## How to use it

**Run the audit:**

```bash
php artisan opconsole:security               # production dependencies
php artisan opconsole:security --include-dev # include development dependencies
php artisan opconsole:security --json
```

Or run it from the **Operations** tab (**Run security scan**), or schedule it daily with `OPCONSOLE_SECURITY_SCHEDULE=true`.

**Review results** on the **Security** tab: vulnerable packages by severity, a scan-freshness meter, each advisory with a ready-to-copy `composer update <package>` command, and configuration warnings.

## How to configure it

```dotenv
OPCONSOLE_COMPOSER=composer
OPCONSOLE_SECURITY_TIMEOUT=120
OPCONSOLE_SECURITY_MAX_AGE=604800
OPCONSOLE_SECURITY_INCLUDE_DEV=false
OPCONSOLE_SECURITY_STORE=opconsole
OPCONSOLE_SECURITY_SCHEDULE=false
OPCONSOLE_SECURITY_TRUSTED_PROXY_HTTPS=false
```

See the complete key list, including `security.risky_actions`, in the [Configuration Reference](/reference/configuration#security-audit-security).

## Security considerations

- **Scope:** `composer audit --format=json --locked --no-interaction --no-plugins --no-scripts` against Composer 2.4+. Production dependencies by default; `--include-dev` (or the config flag) adds development dependencies. Transitive packages are covered.
- **Network requests:** Composer contacts Packagist by default, or whatever advisory repositories your host `composer.json` configures. The request identifies package names and locked versions only. Composer authentication, if configured, is used only by Composer itself and never copied into the report.
- **Exit codes:** OpConsole uses a stable mapping — `0` for a complete scan with no findings, `1` for findings or scan failure — because Composer's own audit exit codes have changed across releases. Inspect the JSON `status` field to distinguish "found vulnerabilities" from "the scan itself failed."
- **Staleness:** a report older than `security.max_age_seconds`, or one that ran before `composer.json`/`composer.lock` changed, is shown as **stale**, never as clean. A failed scan keeps the last successful report visible alongside the failure.
- **Runtime checks:** cover production debug mode, the *presence* (never the value) of `APP_KEY`, session cookie Secure/HttpOnly/SameSite flags, and HTTPS enforcement including an explicit trusted-proxy edge assertion (`security.trusted_proxy_https` — only enable this after configuring Laravel's trusted proxies correctly).

## Limitations

- A lock-based scan does not claim that installed files on disk match the lock file when Composer's installed-package metadata is unavailable — it reports what the lock file says, flagging installed/locked differences only when that metadata is present.
- The scan can fail for reasons outside OpConsole's control: Composer missing or too old, a missing/malformed lock file, an unreachable advisory repository, or another scan already holding the lock. In every case, the last successful report remains visible.
- The audit is **read-only** — dependency updates stay a developer-reviewed Composer workflow, not a dashboard action.

## Troubleshooting

- **Security tab shows "unverified":** no scan has run yet — trigger one from the CLI or the Operations tab.
- **Report marked stale:** either `security.max_age_seconds` has elapsed, or `composer.json`/`composer.lock` changed since the last scan — run the scan again.
- **Scan fails immediately:** confirm the configured `security.composer` executable exists and is at least version 2.4, and that `composer.lock` is present and valid.

See the full threat model in [Security Model](/reference/security).
