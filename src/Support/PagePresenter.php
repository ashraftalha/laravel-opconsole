<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Http\Middleware\AuthorizeOpConsole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Turns ConsoleStatus::status() and LogViewer's result into what the dashboard
 * prints: tiles, action cards, advice links, audit history, filter chips with
 * their URLs, and highlighted log entries. Blade views only print; all logic lives here.
 */
class PagePresenter
{
    private const ADVICE_STYLES = [
        'danger' => ['oc-advice-danger', 'alert-octagon'],
        'warning' => ['oc-advice-warning', 'alert-triangle'],
        'info' => ['oc-advice-info', 'lightbulb'],
    ];

    private const ADVICE_LINKS = [
        'clear' => ['#action-clear', 'Clear caches'],
        'optimize' => ['#action-optimize', 'Optimize performance'],
        'migrate' => ['#action-migrate', 'Run migrations'],
        'logs' => ['#logs', 'View logs'],
        'checks' => ['#checks', 'Review checks'],
    ];

    private const LEVEL_STYLES = [
        'emergency' => 'is-danger',
        'alert' => 'is-danger',
        'critical' => 'is-danger',
        'error' => 'is-danger',
        'warning' => 'is-warning',
        'notice' => 'is-info',
        'info' => 'is-info',
        'debug' => 'is-muted',
    ];

    private const ACTION_LABELS = [
        'clear' => ['Clear caches', 'optimize:clear'],
        'optimize' => ['Optimize performance', 'optimize'],
        'migrate' => ['Run migrations', 'migrate --force --isolated'],
        'log.download' => ['Download log', 'log.download'],
        'log.delete' => ['Delete log', 'log.delete'],
    ];

    public function page(array $status, array $logFiles, ?array $log, ?array $output, ?Authenticatable $user = null): array
    {
        $canRun = $user !== null ? AuthorizeOpConsole::allows('run', $user) : true;
        $canDownload = $user !== null ? AuthorizeOpConsole::allows('download', $user) : true;
        $canDelete = $user !== null ? AuthorizeOpConsole::allows('delete', $user) : true;

        $actions = $this->actions($status, $canRun);
        $checks = $this->checks($status['checks']);

        return [
            'assets' => $this->assets(),
            'indexUrl' => route($this->routeName('index')),
            'environment' => [
                'name' => $status['app']['environment'],
                'production' => $status['app']['production'],
                'debug' => $status['app']['debug'],
            ],
            'permissions' => [
                'run' => $canRun,
                'download' => $canDownload,
                'delete' => $canDelete,
            ],
            'tiles' => $this->tiles($status, $actions),
            'advice' => $this->advice($status['advice'], $actions),
            'actions' => $actions,
            'checks' => $checks,
            'history' => $this->history($status['history'] ?? ['available' => false, 'items' => []]),
            'info' => $status['info'],
            'infoText' => collect($status['info'])
                ->map(fn ($rows, $group) => "== $group\n".collect($rows)->map(fn ($value, $label) => "$label: $value")->implode("\n"))
                ->implode("\n"),
            'dbError' => $status['database']['error'] ? Str::limit($status['database']['error'], 200) : null,
            'output' => $output ? [
                'title' => $actions[$output['action']]['title'] ?? (self::ACTION_LABELS[$output['action']][0] ?? $output['action']),
                'text' => $output['text'] ?: '(No output)',
                'ok' => $output['ok'] ?? true,
            ] : null,
            'logFiles' => $this->files($logFiles, $log, $canDelete),
            'log' => $log ? $this->log($log, $status['app']['environment'], $canDownload) : null,
            'backUrl' => config('opconsole.back_url'),
            'backLabel' => config('opconsole.back_label', 'Dashboard'),
        ];
    }

    /** @return array{css: string, js: string} */
    private function assets(): array
    {
        return [
            'css' => is_file(public_path('vendor/opconsole/opconsole.css'))
                ? asset('vendor/opconsole/opconsole.css')
                : route($this->routeName('asset'), 'opconsole.css'),
            'js' => is_file(public_path('vendor/opconsole/opconsole.js'))
                ? asset('vendor/opconsole/opconsole.js')
                : route($this->routeName('asset'), 'opconsole.js'),
        ];
    }

    private function routeName(string $suffix): string
    {
        return config('opconsole.route.name_prefix', 'opconsole.').$suffix;
    }

    private function checkStatus(array $check): string
    {
        return $check['status'] ?? ($check['ok'] ? 'healthy' : (($check['severity'] ?? 'warning') === 'danger' ? 'critical' : 'warning'));
    }

    private function tiles(array $status, array $actions): array
    {
        ['database' => $db, 'checks' => $checks, 'errors' => $errors] = $status;
        $pending = count($db['pending']);
        $allChecks = collect($checks);
        $failed = $allChecks->filter(function (array $check): bool {
            $checkStatus = $this->checkStatus($check);

            return $checkStatus === 'critical' || ($checkStatus === 'warning' && ($check['severity'] ?? 'warning') !== 'info');
        });
        $unknown = $allChecks->filter(fn (array $c) => $this->checkStatus($c) === 'unknown')->count();

        $checksSub = count($checks).' checks'.($unknown ? ' · '.$unknown.' unverified' : '');

        return [
            [
                'label' => 'Database',
                'value' => ! $db['ok'] ? 'Disconnected' : ($pending ? $pending.' pending migrations' : 'Up to date'),
                'sub' => strtoupper((string) $db['driver']).' · '.$db['ran'].' migrations applied',
                'icon' => 'database',
                'tone' => ! $db['ok'] ? 'is-bad' : ($pending ? 'is-warning' : 'is-ok'),
                'link' => ($pending && isset($actions['migrate'])) ? '#action-migrate' : null,
            ],
            [
                'label' => 'Health checks',
                'value' => $failed->isEmpty() ? 'Healthy' : $failed->count().' '.($failed->count() === 1 ? 'alert' : 'alerts'),
                'sub' => $checksSub,
                'icon' => 'heart-rate',
                'tone' => $failed->contains(fn (array $c) => $this->checkStatus($c) === 'critical')
                    ? 'is-bad'
                    : ($failed->isEmpty() ? 'is-ok' : 'is-warning'),
                'link' => '#checks',
            ],
            [
                'label' => 'Errors in the last 24 hours',
                'value' => $errors['count'] ? $errors['count'].' '.($errors['count'] === 1 ? 'error' : 'errors') : 'None',
                'sub' => $errors['last'] ? 'Latest: '.$errors['last'] : 'Environment: '.$status['app']['environment'],
                'icon' => 'bug',
                'tone' => $errors['count'] ? 'is-bad' : 'is-ok',
                'link' => '#logs',
            ],
        ];
    }

    /** Checks grouped by area, critical problems first across both groups and items, with explicit status distinction. */
    private function checks(array $checks): array
    {
        $order = fn (array $check): int => match ($this->checkStatus($check)) {
            'critical' => 0,
            'warning' => $check['severity'] === 'info' ? 2 : 1,
            'unknown' => 3,
            default => 4,
        };

        $style = fn (array $check): array => match ($this->checkStatus($check)) {
            'critical' => ['is-bad', 'x-circle', 'Critical'],
            'warning' => $check['severity'] === 'info'
                ? ['is-info', 'info', 'Advisory']
                : ['is-warning', 'alert-circle', 'Warning'],
            'unknown' => ['is-unknown', 'help-circle', 'Unknown'],
            default => ['is-ok', 'check-circle', 'Healthy'],
        };

        $all = collect($checks);
        $healthyCount = $all->filter(fn (array $c) => $this->checkStatus($c) === 'healthy')->count();
        $criticalCount = $all->filter(fn (array $c) => $this->checkStatus($c) === 'critical')->count();
        $warningCount = $all->filter(fn (array $c) => $this->checkStatus($c) === 'warning' && $c['severity'] !== 'info')->count();
        $advisoryCount = $all->filter(fn (array $c) => $this->checkStatus($c) === 'warning' && $c['severity'] === 'info')->count();
        $unknownCount = $all->filter(fn (array $c) => $this->checkStatus($c) === 'unknown')->count();
        $problems = $criticalCount + $warningCount;

        $summaryParts = [$healthyCount.' of '.$all->count().' verified healthy'];
        if ($criticalCount > 0) {
            $summaryParts[] = $criticalCount.' critical';
        }
        if ($warningCount > 0) {
            $summaryParts[] = $warningCount.' '.($warningCount === 1 ? 'warning' : 'warnings');
        }
        if ($advisoryCount > 0) {
            $summaryParts[] = $advisoryCount.' '.($advisoryCount === 1 ? 'advisory' : 'advisories');
        }
        if ($unknownCount > 0) {
            $summaryParts[] = $unknownCount.' unknown';
        }

        $groups = $all->groupBy('group')->map(function ($group, $name) use ($order, $style) {
            $groupCritical = $group->filter(fn (array $c) => $this->checkStatus($c) === 'critical')->count();
            $groupWarning = $group->filter(fn (array $c) => $this->checkStatus($c) === 'warning' && $c['severity'] !== 'info')->count();
            $groupHealthy = $group->filter(fn (array $c) => $this->checkStatus($c) === 'healthy')->count();
            $groupUnknown = $group->filter(fn (array $c) => $this->checkStatus($c) === 'unknown')->count();

            return [
                'name' => $name,
                'passed' => $groupHealthy,
                'unknown' => $groupUnknown,
                'total' => $group->count(),
                'priority' => $groupCritical > 0 ? 0 : ($groupWarning > 0 ? 1 : 2),
                'items' => $group->sortBy($order)->map(function (array $check) use ($style) {
                    [$cls, $icon, $badge] = $style($check);

                    return $check + [
                        'statusKey' => $this->checkStatus($check),
                        'class' => $cls,
                        'icon' => $icon,
                        'badge' => $badge,
                    ];
                })->values()->all(),
            ];
        })->sortBy('priority')->values()->all();

        return [
            'summary' => implode(' · ', $summaryParts),
            'tone' => $criticalCount > 0 ? 'is-bad' : ($warningCount > 0 ? 'is-warning' : 'is-ok'),
            'hasProblems' => $problems > 0,
            'groups' => $groups,
        ];
    }

    private function advice(array $advice, array $actions): array
    {
        return array_map(function ($item) use ($actions) {
            $action = $item['action'];
            $hasTarget = $action !== null
                && isset(self::ADVICE_LINKS[$action])
                && (! in_array($action, ['clear', 'optimize', 'migrate'], true) || isset($actions[$action]));

            return [
                'class' => self::ADVICE_STYLES[$item['level']][0],
                'icon' => self::ADVICE_STYLES[$item['level']][1],
                'text' => $item['text'],
                'link' => $hasTarget ? self::ADVICE_LINKS[$action] : null,
            ];
        }, $advice);
    }

    private function actions(array $status, bool $canRun): array
    {
        $pending = $status['database']['pending'];
        $dbOk = (bool) $status['database']['ok'];
        $production = $status['app']['production'];
        $enabledActions = config('opconsole.actions', []);
        $last = function (string $action) use ($status) {
            $run = $status['lastRuns'][$action] ?? null;

            return $run && $run['at']
                ? ['ok' => $run['ok'], 'text' => 'Last run '.Carbon::parse($run['at'])->locale('en')->diffForHumans().' — '.$run['by'].($run['ok'] ? '' : ' (failed)')]
                : null;
        };

        $readOnlyNote = ! $canRun ? 'Read-only access: your account does not have action execution permission.' : null;
        $migrateDisabledReason = $readOnlyNote
            ?? (! $dbOk ? 'Unavailable while the database is disconnected.' : (! $pending ? 'No pending migrations to apply.' : null));

        $all = [
            'clear' => [
                'icon' => 'trash',
                'title' => 'Clear caches',
                'command' => 'php artisan optimize:clear',
                'risk' => 'low',
                'riskLabel' => 'Low risk · Cache flush',
                'button' => 'Clear now',
                'style' => 'oc-btn-soft-primary',
                'url' => route($this->routeName('run'), 'clear'),
                'text' => 'Clears cached configuration, routes, views, events, and default application cache. Active sessions and action locks are preserved.',
                'confirmTitle' => 'Clear application caches',
                'confirm' => 'Clear all compiled and application caches? Initial requests afterward may be slightly slower while caches warm up.',
                'confirmAccept' => 'Clear caches',
                'enabled' => (bool) ($enabledActions['clear'] ?? false) && $canRun,
                'disabledReason' => $readOnlyNote,
                'pending' => [],
                'last' => $last('clear'),
            ],
            'optimize' => [
                'icon' => 'rocket',
                'title' => 'Optimize performance',
                'command' => 'php artisan optimize',
                'risk' => 'low',
                'riskLabel' => 'Low risk · Compile caches',
                'button' => 'Optimize',
                'style' => 'oc-btn-soft-success',
                'url' => route($this->routeName('run'), 'optimize'),
                'text' => 'Compiles configuration, routes, events, and Blade views for faster request handling. Clear caches after changing .env.',
                'confirmTitle' => 'Compile framework caches',
                'confirm' => $production
                    ? 'Compile and cache configuration, routes, events, and views?'
                    : 'Non-production environment: cached configuration and routes ignore .env and route edits until cleared. Continue?',
                'confirmAccept' => 'Optimize now',
                'enabled' => (bool) ($enabledActions['optimize'] ?? false) && $canRun,
                'disabledReason' => $readOnlyNote,
                'pending' => [],
                'last' => $last('optimize'),
            ],
            'migrate' => [
                'icon' => 'database',
                'title' => 'Run migrations',
                'command' => 'php artisan migrate --force --isolated',
                'risk' => 'high',
                'riskLabel' => 'High risk · Schema change',
                'button' => $pending ? 'Run '.count($pending).' '.(count($pending) === 1 ? 'migration' : 'migrations') : 'Nothing pending',
                'style' => $pending ? 'oc-btn-danger' : 'oc-btn-soft-secondary',
                'url' => route($this->routeName('run'), 'migrate'),
                'text' => 'Applies pending database schema migrations forward-only under an isolated lock. Never runs destructive resets or rollbacks.',
                'confirmTitle' => 'Run database migrations',
                'confirm' => 'Apply '.count($pending).' pending database '.(count($pending) === 1 ? 'migration' : 'migrations').' in ['.$status['app']['environment'].']? Ensure a recent database backup exists before modifying schema.',
                'confirmAccept' => 'Run migrations',
                'enabled' => (bool) ($enabledActions['migrate'] ?? false) && $canRun && $dbOk && $pending,
                'disabledReason' => $migrateDisabledReason,
                'pending' => $pending,
                'last' => $last('migrate'),
            ],
        ];

        return array_filter($all, fn ($action, $key) => $enabledActions[$key] ?? false, ARRAY_FILTER_USE_BOTH);
    }

    private function history(array $history): array
    {
        $items = array_map(function (array $item): array {
            [$label, $code] = self::ACTION_LABELS[$item['action']] ?? [$item['action'], $item['action']];
            [$statusLabel, $statusClass] = match ($item['status']) {
                'succeeded' => ['Succeeded', 'is-ok'],
                'failed' => ['Failed', 'is-bad'],
                'started' => ['Started', 'is-info'],
                default => ['Interrupted / Unknown', 'is-unknown'],
            };

            $startedCarbon = $item['startedAt'] ? Carbon::parse($item['startedAt']) : null;
            $duration = $item['durationMs'] !== null
                ? ($item['durationMs'] < 1000 ? $item['durationMs'].' ms' : number_format($item['durationMs'] / 1000, 2).' s')
                : '—';

            $detailsParts = [];
            if ($item['subject']) {
                $detailsParts[] = $item['subject'];
            }
            if ($item['exitCode'] !== null) {
                $detailsParts[] = 'exit '.$item['exitCode'];
            }
            if ($item['error']) {
                $detailsParts[] = $item['error'];
            } elseif ($item['status'] === 'unknown') {
                $detailsParts[] = 'Incomplete: no completion recorded before lock timeout';
            }

            return [
                'actionLabel' => $label,
                'command' => $code,
                'actor' => $item['actor'],
                'status' => $item['status'],
                'statusLabel' => $statusLabel,
                'statusClass' => $statusClass,
                'startedRelative' => $startedCarbon ? $startedCarbon->locale('en')->diffForHumans() : '—',
                'startedExact' => $startedCarbon ? $startedCarbon->copy()->utc()->format('Y-m-d H:i:s \U\T\C') : null,
                'startedIso' => $item['startedAt'],
                'duration' => $duration,
                'details' => $detailsParts !== [] ? implode(' · ', $detailsParts) : '—',
            ];
        }, $history['items'] ?? []);

        return [
            'available' => (bool) ($history['available'] ?? false),
            'items' => $items,
        ];
    }

    /** File cards; every file except the newest (still being written) can be deleted by authorized operators. */
    private function files(array $files, ?array $log, bool $canDelete): array
    {
        return array_map(function (array $file, int $i) use ($log, $canDelete): array {
            $deletable = $i > 0 && config('opconsole.logs.allow_delete', true) && $canDelete;

            return [
                'name' => $file['name'],
                'meta' => Number::fileSize($file['size']).' · '.$file['modified']->locale('en')->diffForHumans(),
                'url' => route($this->routeName('index'), ['log' => $file['name']]).'#logs',
                'deleteUrl' => $deletable ? route($this->routeName('log.delete'), $file['name']) : null,
                'active' => $file['name'] === ($log['name'] ?? null),
                'deletable' => $deletable,
            ];
        }, $files, array_keys($files));
    }

    private function log(array $log, string $currentEnv, bool $canDownload): array
    {
        $url = fn (array $params = []) => route($this->routeName('index'), array_filter(
            $params + [
                'log' => $log['name'],
                'env' => $log['env'] ?? 'all',
                'level' => $log['level'],
                'q' => $log['search'],
            ],
            fn ($value) => $value !== null && $value !== '',
        )).'#logs';

        $envChips = count($log['envs']) > 1 ? [
            ['label' => 'All environments', 'count' => array_sum($log['envs']), 'url' => $url(['env' => 'all', 'level' => null]), 'active' => $log['env'] === null, 'env' => null],
            ...array_map(fn ($env, $count) => [
                'label' => $env.($env === $currentEnv ? ' (current)' : ''), 'count' => $count, 'url' => $url(['env' => $env, 'level' => null]), 'active' => $log['env'] === $env, 'env' => $env,
            ], array_keys($log['envs']), $log['envs']),
        ] : [];

        $levelChips = [
            ['label' => 'All', 'count' => $log['total'], 'url' => $url(['level' => null]), 'active' => ! $log['level'], 'style' => '', 'level' => null],
            ...array_map(fn ($level, $count) => [
                'label' => $level, 'count' => $count, 'url' => $url(['level' => $level]), 'active' => $log['level'] === $level, 'style' => self::LEVEL_STYLES[$level] ?? 'is-muted', 'level' => $level,
            ], array_keys($log['counts']), $log['counts']),
        ];

        $hasActiveFilters = $log['level'] !== null || $log['search'] !== '' || $log['env'] !== null;
        $allTotal = $log['allTotal'] ?? $log['total'];

        return [
            'name' => $log['name'],
            'env' => $log['env'] ?? 'all',
            'level' => $log['level'],
            'search' => $log['search'],
            'envChips' => $envChips,
            'levelChips' => $levelChips,
            'clearSearchUrl' => $url(['q' => null]),
            'resetFiltersUrl' => $hasActiveFilters ? $url(['env' => 'all', 'level' => null, 'q' => null]) : null,
            'downloadUrl' => (config('opconsole.logs.allow_download', true) && $canDownload) ? route($this->routeName('log'), $log['name']) : null,
            'summary' => 'Showing '.count($log['entries']).' of '.$log['shown'].' entries'.($log['shown'] !== $log['total'] ? ' (total: '.$log['total'].')' : ''),
            'emptyMessage' => $allTotal === 0 ? 'This log file is empty or contains no parsable entries.' : 'No entries match the active filters.',
            'truncated' => $log['truncated'],
            'groups' => array_map(fn ($group) => $group + ['style' => self::LEVEL_STYLES[$group['level']] ?? 'is-muted', 'message' => Str::limit($group['message'], 180)], $log['groups']),
            'entries' => array_map(fn ($entry) => [
                'level' => $entry['level'],
                'style' => self::LEVEL_STYLES[$entry['level']] ?? 'is-muted',
                'meta' => $entry['env'].' · '.$entry['time'],
                'messageHtml' => $this->highlight($entry['message'], $log['search']),
                'detailsHtml' => $entry['details'] ? $this->highlight($entry['details'], $log['search']) : null,
                'copy' => '['.$entry['time'].'] '.$entry['env'].'.'.strtoupper($entry['level']).': '.($entry['details'] ?: $entry['message']),
            ], $log['entries']),
            'moreUrl' => $log['more'] ? $url(['limit' => $log['limit'] + 50]) : null,
        ];
    }

    /**
     * Escaped text with the search term wrapped in <mark>. Safe to print unescaped.
     * Highlighting runs on the raw text before escaping, so entity text (amp, lt, quot)
     * in a search term can never wedge a <mark> inside an emitted entity.
     */
    private function highlight(string $text, string $search): string
    {
        if ($search === '') {
            return e($text);
        }

        $parts = preg_split('/('.preg_quote($search, '/').')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return e($text);
        }

        return collect($parts)->map(fn ($part, $i) => $i % 2 === 1 ? '<mark>'.e($part).'</mark>' : e($part))->implode('');
    }
}
