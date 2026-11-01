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
