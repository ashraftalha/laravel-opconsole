# Changelog

All notable changes to Laravel OpConsole are documented here. This project follows [Semantic Versioning](https://semver.org/).

## [0.1.0] - Unreleased

Initial release.

### Added
- System health dashboard: Laravel/PHP version, environment, database connectivity, pending migrations, cache state, filesystem permissions, security basics, database/queue tables, mail/URL checks.
- Three allow-listed Artisan operations: `optimize:clear`, `optimize`, `migrate --force --isolated`, each individually disable-able, serialized with a cache lock, and audited.
- Log viewer: level/environment/text filtering, repeated-error grouping, download, delete with symlink/path-traversal protection.
- `AshrafTalha\OpConsole\Contracts\HealthCheck` extension point for host-specific checks.
- Deny-by-default authorization via two configurable Gates (view vs. run), with a fail-closed fallback outside the `local` environment.
- `opconsole_runs` audit table recording started/succeeded/failed status for every action and log delete/download.
- Self-contained, publishable CSS/JS; no required host theme or build step.
- Full Testbench test suite, Larastan level 5, Pint.
- `docs/examples/sel-lab-dev/`: a side-by-side dev/staging config for mounting at `/admin/opconsole` without touching an existing admin console.

### Fixed (pre-release hardening pass)
- Guests on a host with no named `login` route no longer get an uncaught `RouteNotFoundException` (HTTP 500). OpConsole now checks the configured guard itself instead of delegating to Laravel's `auth` middleware alias, and returns a controlled `401` when no login destination is configured or reachable. `route.middleware` no longer includes `auth` by default.
- Fixed a self-invalidating action lock: `Cache::store($store) instanceof LockProvider` is always false (`Cache::store()` returns a `Repository` wrapper, not the underlying store), so the lock silently fell back to `cache.default` on every call — exactly the bug the store isolation was meant to prevent. Now checks `Cache::store($store)->getStore() instanceof LockProvider` and locks on the underlying store directly. See `docs/SECURITY.md` for the full post-mortem.
- Verified production/staging deny-by-default authorization against a real clean Laravel 13 install, not only the test suite.

### Changed
- Minimum supported Laravel widened to `^12.0|^13.0` (was `^13.0` only). Verified against the real framework, not just the version constraint: the full test suite, Larastan, and Pint pass against both Laravel 12 + Testbench 10 and Laravel 13 + Testbench 11, including the one test (`AuthorizationTest::test_production_environment_denies_access_without_explicit_gates`) that had to account for the CSRF middleware class being renamed between the two (`ValidateCsrfToken` on 12.x, `PreventRequestForgery` on 13.x). PHP minimum stays `^8.3`. CI matrix updated to cover both.
