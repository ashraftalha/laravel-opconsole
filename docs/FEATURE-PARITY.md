# Feature parity with sel-lab's `/admin/maintenance`

Source: `sel-lab/docs/admin-maintenance-package-analysis.md` and the implementation it analyzed (`app/Services/{Maintenance,LogViewer,MaintenanceView}Service.php`, `app/Http/Controllers/Admin/MaintenanceController.php`). Feature IDs (F1–F22) match that document's inventory.

| # | Feature | In OpConsole? | Notes |
|---|---|---|---|
| F1 | Status page with 3 tiles | Yes | `PagePresenter::tiles()` |
| F2 | Prioritised advice list | Yes | `ConsoleStatus::advice()` |
| F3 | Health checks in groups, severity, value/fix hint | Yes | `HealthChecks`; same array shape |
| F4 | Host-vs-APP_URL mismatch check | Yes | `HealthChecks::builtIn()`, loopback-equivalence kept |
| F5 | System information panel + copy-for-support | Yes | `PagePresenter::page()['infoText']` |
| F6 | Clear caches (`optimize:clear`) | Yes | |
| F7 | Optimize (`optimize`) | Yes | |
| F8 | Migrate forward only, disabled when nothing pending | Yes | |
| F9 | Colour-stripped output flashed to next page load | Yes | `session('opconsole_output')` |
| F10 | Last-run record per action | Yes | Moved from `site_settings` to `opconsole_runs` (own table, doubles as audit log) |
| F11 | Log file list, newest first | Yes | |
| F12 | Log viewer: tail, filters, grouping, "show more" | Yes | |
| F13 | Log download | Yes | Can be disabled via `logs.allow_download` |
| F14 | Log delete (not the newest file) | Yes | Can be disabled via `logs.allow_delete`; now audited (sel-lab's did not write an audit entry — see S-D1 below) |
| F15 | Throttle on actions | Yes | Extended to the log-delete route too (sel-lab only throttled actions) |
| F16 | Not linked from any sidebar | Yes, by construction | The package registers routes only; nothing links to them |
| F17 | Real maintenance mode (`artisan down/up`) | **Not included**, by design | Out of scope; do not confuse with this package |
| F18 | Audit trail of log deletes/downloads | **Improved**: now included | sel-lab only logged the 3 actions via `Log::info`; this package writes an `opconsole_runs` row for every action and every log delete/download |
| F19 | Per-action authorization (view vs. run) | **Improved**: now included | sel-lab had "any authenticated user"; this package has separate `view_gate`/`run_gate`, fail-closed by default |
| F20 | Localization | Partial | `lang/en` published; the handful of flash messages go through `__()`. Full i18n of check labels/hints is future work, same as the source analysis recommended deferring |
| F21 | Queue-based/async execution | **Not included**, by design | See README "Known limitations" |
| F22 | `migrate` persistence when the audit table is absent | **Fixed** | sel-lab's `run()` did not guard the `SiteSetting::updateOrCreate` call (bug R-M1 in the analysis). `ActionRunner` wraps every audit write in try/catch and never turns a successful command into a reported failure |

## Deliberately not carried over (host-specific; stays in `sel-lab` if it migrates to this package)

- Hero image / upload-directory checks, the 20 MB PDF upload-limit check, the `gd`/QR-code extension requirement, and the "Saudi Arabia time" copy — these are `sel-lab` business rules, not generic to any Laravel app. They should be registered by `sel-lab` itself via `config('opconsole.checks')` if it adopts this package (see `SEL-LAB-INTEGRATION.md`).
- `<x-developer-credit/>`, `@include('favicon')`, and the `route('admin.dashboard')` back-link — these are host branding/navigation; replaced with the configurable `back_url`/`back_label` and no default branding.
- The `admin.*` view-composer injection of `$ui` (`AdminViewService`) — it was unused by the maintenance views in `sel-lab` and does not exist in this package's view namespace at all.

## Also fixed along the way (not required for parity, but verified against the source analysis)

- **S-L1** (`highlight()` wedging a `<mark>` inside an already-escaped entity like `&amp;`): fixed by highlighting before escaping each segment, rather than regex-matching the escaped search term against already-escaped text. Covered by `PagePresenterTest::test_searching_for_entity_text_does_not_corrupt_escaped_output`.
- **S-F1** (symlinked `.log` file inside the logs directory): `LogViewer::path()` now resolves with `realpath()` and rejects anything outside the configured directory.
