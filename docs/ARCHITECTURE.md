# Architecture

One service provider, one controller, five small `Support` classes, one model, one Gate-backed middleware. No facades, no repository layer, no DTO hierarchy, no per-command action classes — none of these were justified by a real problem in the source implementation this package was extracted from.

```
src/
├── OpConsoleServiceProvider.php     Config merge, routes, views, translations, migrations, publishing, default Gates
├── Http/
│   ├── Controllers/OpConsoleController.php   4 actions: index, downloadLog, deleteLog, run
│   └── Middleware/AuthorizeOpConsole.php     Gate::allows() wrapper, deny by default
├── Models/OpConsoleRun.php          Audit row: started/succeeded/failed
├── Contracts/HealthCheck.php        The one extension point
└── Support/
    ├── ConsoleStatus.php            Pure read model: database, caches, checks, info, advice
    ├── ActionRunner.php             Writes (Artisan calls + audit), separate risk class from ConsoleStatus
    ├── HealthChecks.php             Built-in checks + resolves config('opconsole.checks')
    ├── LogViewer.php                Tail-read, parse, filter, group, delete/download storage/logs
    └── PagePresenter.php            Turns ConsoleStatus + LogViewer output into view data; Blade only prints
```

## Why this shape

- **ConsoleStatus vs. ActionRunner split**: reads and writes have different risk profiles and different test needs (`Artisan::shouldReceive` vs. a pure fixture). Keeping them separate mirrors how they're authorized (`view_gate` vs. `run_gate`).
- **PagePresenter does all the formatting**: Blade views contain no business logic — grouping, ordering, highlighting, and URL building all happen here. This is testable without a view-rendering round trip and keeps views easy to override.
- **HealthChecks is the one extension point**: everything else is either a closed allow-list (the three Artisan actions) or a plain read. Host-specific checks register as classes in `config('opconsole.checks')`, resolved from the container, each returning the same array shape the built-in checks return.
- **No queue execution**: the three actions are supposed to be fast. Making them async adds a new failure mode (worker not running) that is itself one of the things this page would need to report on. Revisit only if a host's migrations genuinely exceed the web timeout.
- **OpConsoleRun doubles as "last run" and audit log**: one table, append-mostly (started rows are updated once to succeeded/failed), no FKs to the host's user table (`user_id` is a plain string so it works with int/UUID/ULID user keys alike).

## Request flow

```
GET  {prefix}/            → opconsole.authorize:view → ConsoleStatus::status() + LogViewer::files()/read() → PagePresenter::page() → view
GET  {prefix}/logs/{file} → opconsole.authorize:view → LogViewer::download()
DELETE {prefix}/logs/{file} → opconsole.authorize:run + throttle → LogViewer::delete() → ActionRunner::record()
POST {prefix}/{action}    → opconsole.authorize:run + throttle → ActionRunner::run() (Cache::lock, Artisan::call, audit row)
```

## Configuration-only extension points

See `config/opconsole.php`. No closures are stored in config (that would break `config:cache`); the only callable extension point (`checks`) is a list of class names resolved from the container.
