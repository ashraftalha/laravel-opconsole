# Release Checklist — Laravel OpConsole

Use this checklist before tagging any release of `ashraftalha/laravel-opconsole`. Do **not** tag or publish until every blocking item is verified on the exact commit being released.

---

## Release Scope: `v1.11.0`

This release adds a Failed jobs tab with per-job retry (`POST failed-jobs/{id}/retry`, run Gate or `queue_retry` action Gate, audited), Laravel Octane and Swoole health checks with a Reload Octane workers operation (only when Octane is installed), a guard that refuses timed operations inside Swoole workers, more System information, and fixes the Health tab opening empty. No routes, Gates, migrations or config keys were removed. Review `CHANGELOG.md`, `CONFIGURATION.md` and `UPGRADE.md` against the exact release commit.

---

## 1. Automated Quality Gates

- [ ] `composer validate --strict --no-check-lock` passes with zero warnings or errors.
- [ ] `vendor/bin/pint --test` passes with zero formatting diffs.
- [ ] `composer stan` (Larastan level 5) passes on **Laravel 12** (`^12.0` / Testbench `^10.0`).
- [ ] `composer stan` (Larastan level 5) passes on **Laravel 13** (`^13.0` / Testbench `^11.0`).
- [ ] `composer test` passes all unit and feature tests on **Laravel 12 + Orchestra Testbench 10**.
- [ ] `composer test` passes all unit and feature tests on **Laravel 13 + Orchestra Testbench 11**.
- [ ] `composer dump-autoload -o` completes without PSR-4 classmap warnings.

---

## 2. Security & Fail-Closed Invariants

- [ ] Guests receive a redirect to `authentication.login_route` when valid, or HTTP `401` when missing, disabled, or parameterized (never HTTP `500`).
- [ ] Authenticated users in `production`, `staging`, and `testing` receive HTTP `403` when host Gates are not explicitly defined.
- [ ] Custom `authentication.guard` governs both Gate evaluation (`Gate::forUser`) and audit attribution (`OpConsoleRun`), failing closed (`403`) on invalid guard names.
- [ ] Overriding `route.middleware` (even to `[]`) preserves `web` session and CSRF protection (`419` on forged POST/DELETE).
- [ ] The asset route serves only `opconsole.css`, `opconsole.js` and `opconsole.svg`, behind the view Gate.
- [ ] `ActionRunner` exposes only the eight documented actions (`optimize:clear`, `optimize`, `migrate --force --isolated`, `down`, `up`, optional `storage:link`, `opconsole:security`, and `octane:reload`) with zero user-supplied arguments. The only parameterised operation is failed-job retry, whose ID must match `FailedJobs::ID_PATTERN` and exist in the failed-job store.
- [ ] Inside a Swoole worker (Octane), timed actions are refused unless `octane.allow_actions_in_swoole` is true.
- [ ] Unsafe lock stores (`null`, `array` outside unit tests, `lock_store === cache.default` without separate lock storage, overlapping `file` lock directories, missing stores) fail closed without executing Artisan.
- [ ] `LogViewer::files()` and `LogViewer::path()` reject path traversal, invalid filenames, and external symlinks.
- [ ] Rendered HTML escapes all log messages, stack traces, search highlights, and command outputs; standalone views emit zero inline `<script>` blocks, zero inline `style=` attributes, and zero mandatory CDN requests.
- [ ] Exception messages and Artisan outputs sanitize DSN passwords, `password=`/`token=`/`secret=` parameters, and `base_path()` prefixes before display or database persistence.
- [ ] On a PHP-FPM runtime **without** `pcntl`, timer-bound action cards are disabled with the runtime reason and a forced POST is refused and audited; `security_scan` still runs.
- [ ] On a PHP-FPM runtime **with** `pcntl`, a migration longer than 10 seconds completes, on both the lowest supported Laravel 12/13 releases and the latest.
- [ ] `audit.policy=required` with the audit table unavailable refuses actions, log deletes, and downloads.
- [ ] Notification failure logs contain no webhook URLs.
- [ ] High-risk confirmations (`data-confirm-risk="high"`) require holding the accept button or pressing it twice; a single click or a tap-and-release before ~1.2s does not submit the form. Keyboard (Space/Enter hold) and `prefers-reduced-motion` both still work.
- [ ] Dashboard renders correctly in light and dark themes (`OPCONSOLE_THEME`) and at desktop/tablet/mobile widths; no inline `<script>`, inline `style=`, or external CDN requests.

---

## 3. Clean-Application Smoke Verification

Verify in disposable fresh **Laravel 12** and **Laravel 13** applications (outside Testbench):

- [ ] `composer require ashraftalha/laravel-opconsole` auto-discovers `OpConsoleServiceProvider`.
- [ ] `php artisan vendor:publish --tag=opconsole-config` and `--tag=opconsole-assets` succeed.
- [ ] Fresh install and upgrade migrations succeed on Laravel 12 and Laravel 13; rollback succeeds cleanly.
- [ ] `php artisan config:cache`, `php artisan route:cache`, and `php artisan view:cache` all succeed.
- [ ] `OPCONSOLE_ENABLED=false` registers zero `opconsole.*` routes.
- [ ] `composer remove ashraftalha/laravel-opconsole` leaves the host application healthy.

---

## 4. CI & Repository Hygiene

- [ ] Push branch and verify `.github/workflows/tests.yml` passes on GitHub Actions across PHP `8.3`, `8.4`, `8.5` × Laravel `12.*`, `13.*` × `prefer-stable`, `prefer-lowest`, plus `static-analysis` and `clean-install`.
- [ ] Confirm no untracked temporary caches, `.env` files, SQLite files, or test artifacts are staged (`git status` clean).
- [ ] Confirm `CHANGELOG.md`, `README.md`, `docs/INSTALLATION.md`, `docs/CONFIGURATION.md`, `docs/UPGRADE.md`, `docs/SECURITY.md`, and `docs/ARCHITECTURE.md` match the exact shipped configuration and behavior.
- [ ] Confirm `LICENSE` (MIT) and `composer.json` metadata are accurate and contain no claim of official Laravel affiliation.
- [ ] Run a repository-wide case-insensitive scan confirming zero project-specific domain, path, or legacy integration references remain.

---

## 5. Manual Release Steps (Maintainer Only)

1. Merge the verified release branch into `main` after GitHub Actions passes green.
2. Stamp the release date and version heading in `CHANGELOG.md`.
3. Create an annotated Git tag (`git tag -a vX.Y.Z -m "Release vX.Y.Z"`).
4. Push `main` and the annotated tag (`git push origin main --tags`).
5. Verify Packagist webhook / VCS tag resolution in a clean disposable Laravel project.
