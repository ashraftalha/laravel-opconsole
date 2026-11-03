# Release Checklist — Laravel OpConsole

Use this checklist before tagging any release of `ashraftalha/laravel-opconsole`. Do **not** tag or publish until every blocking item is verified on the exact commit being released.

---

## Release Scope: `v1.7.0`

This release adds a host Composer dependency security audit (`opconsole:security`) with stored reports, manifest freshness, and optional daily scheduling, plus a dashboard Security tab covering advisories and Laravel runtime configuration warnings. Review `CHANGELOG.md`, `CONFIGURATION.md`, and `UPGRADE.md` against the exact release commit.

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
- [ ] `ActionRunner` exposes only the six documented actions (`optimize:clear`, `optimize`, `migrate --force --isolated`, `down`, `up`, and optional `storage:link`) with zero user-supplied arguments.
- [ ] Unsafe lock stores (`null`, `array` outside unit tests, `lock_store === cache.default` without separate lock storage, overlapping `file` lock directories, missing stores) fail closed without executing Artisan.
- [ ] `LogViewer::files()` and `LogViewer::path()` reject path traversal, invalid filenames, and external symlinks.
- [ ] Rendered HTML escapes all log messages, stack traces, search highlights, and command outputs; standalone views emit zero inline `<script>` blocks, zero inline `style=` attributes, and zero mandatory CDN requests.
- [ ] Exception messages and Artisan outputs sanitize DSN passwords, `password=`/`token=`/`secret=` parameters, and `base_path()` prefixes before display or database persistence.

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
3. Create an annotated Git tag (`git tag -a v0.2.0 -m "Release v0.2.0"`).
4. Push `main` and the annotated tag (`git push origin main --tags`).
5. Verify Packagist webhook / VCS tag resolution in a clean disposable Laravel project.
