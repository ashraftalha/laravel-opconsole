<?php

namespace AshrafTalha\OpConsole\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Turns ConsoleStatus::status() and LogViewer's result into what the dashboard
 * prints: tiles, action cards, advice links, filter chips with their URLs,
 * highlighted log entries. Blade views only print; all logic lives here.
 */
class PagePresenter
{
    private const ADVICE_STYLES = ['danger' => ['oc-advice-danger', 'uil-exclamation-octagon'], 'warning' => ['oc-advice-warning', 'uil-exclamation-triangle'], 'info' => ['oc-advice-info', 'uil-lightbulb-alt']];

    private const ADVICE_LINKS = ['clear' => ['#action-clear', 'Clear caches'], 'optimize' => ['#action-optimize', 'Optimize performance'], 'migrate' => ['#action-migrate', 'Run migrations'], 'logs' => ['#logs', 'View logs']];

    private const LEVEL_STYLES = ['emergency' => 'is-danger', 'alert' => 'is-danger', 'critical' => 'is-danger', 'error' => 'is-danger', 'warning' => 'is-warning', 'notice' => 'is-info', 'info' => 'is-info', 'debug' => 'is-muted'];

    public function page(array $status, array $logFiles, ?array $log, ?array $output): array
    {
        return [
            'tiles' => $this->tiles($status),
            'advice' => $this->advice($status['advice']),
            'actions' => $this->actions($status),
            'checks' => $this->checks($status['checks']),
            'info' => $status['info'],
            'infoText' => collect($status['info'])
                ->map(fn ($rows, $group) => "== $group\n".collect($rows)->map(fn ($value, $label) => "$label: $value")->implode("\n"))
                ->implode("\n"),
            'dbError' => $status['database']['error'] ? Str::limit($status['database']['error'], 200) : null,
            'output' => $output ? ['title' => $this->actions($status)[$output['action']]['title'] ?? $output['action'], 'text' => $output['text'] ?: '(No output)'] : null,
            'logFiles' => $this->files($logFiles, $log),
            'log' => $log ? $this->log($log, $status['app']['environment']) : null,
            'backUrl' => config('opconsole.back_url'),
            'backLabel' => config('opconsole.back_label', 'Back'),
        ];
    }

    private function routeName(string $suffix): string
    {
        return config('opconsole.route.name_prefix', 'opconsole.').$suffix;
    }

    private function tiles(array $status): array
    {
        ['database' => $db, 'checks' => $checks, 'errors' => $errors] = $status;
        $pending = count($db['pending']);
        $failed = collect($checks)->where('ok', false)->where('severity', '!=', 'info');

        return [
            [
                'label' => 'Database', 'value' => ! $db['ok'] ? 'Disconnected' : ($pending ? $pending.' pending migrations' : 'Up to date'),
                'sub' => strtoupper($db['driver']).' · '.$db['ran'].' migrations applied', 'icon' => 'uil-database',
                'color' => ! $db['ok'] ? '#dc2626' : ($pending ? '#f59e0b' : '#16a34a'), 'link' => $pending ? '#action-migrate' : null,
            ],
            [
                'label' => 'Health checks', 'value' => $failed->isEmpty() ? 'Healthy' : $failed->count().' alerts', 'sub' => count($checks).' checks', 'icon' => 'uil-heart-rate',
                'color' => $failed->contains('severity', 'danger') ? '#dc2626' : ($failed->isEmpty() ? '#16a34a' : '#f59e0b'), 'link' => '#checks',
            ],
            [
                'label' => 'Errors in the last 24 hours', 'value' => $errors['count'] ? $errors['count'].' errors' : 'None',
                'sub' => $errors['last'] ? 'Latest: '.$errors['last'] : 'Environment: '.$status['app']['environment'], 'icon' => 'uil-bug', 'color' => $errors['count'] ? '#dc2626' : '#16a34a', 'link' => '#logs',
            ],
        ];
    }

    /** Checks grouped by area, problems first in each group, with a one-line summary. */
    private function checks(array $checks): array
    {
        $order = fn ($check) => $check['ok'] ? 3 : ['danger' => 0, 'warning' => 1, 'info' => 2][$check['severity']];
        $style = fn ($check) => match (true) {
            $check['ok'] => ['is-ok', 'uil-check-circle'],
            $check['severity'] === 'danger' => ['is-bad', 'uil-times-circle'],
            $check['severity'] === 'warning' => ['is-warning', 'uil-exclamation-circle'],
            default => ['is-info', 'uil-info-circle'],
        };
        $all = collect($checks);
        $problems = $all->where('ok', false)->where('severity', '!=', 'info')->count();
        $suggestions = $all->where('ok', false)->where('severity', 'info')->count();

        return [
            'summary' => $all->where('ok', true)->count().' of '.$all->count().' passed'
                .($problems ? ' · '.$problems.' need attention' : '')
                .($suggestions ? ' · '.$suggestions.' suggestions' : ''),
            'tone' => $problems ? ($all->contains(fn ($c) => ! $c['ok'] && $c['severity'] === 'danger') ? 'is-bad' : 'is-warning') : 'is-ok',
            'groups' => $all->groupBy('group')->map(fn ($group, $name) => [
                'name' => $name,
                'passed' => $group->where('ok', true)->count(),
                'total' => $group->count(),
                'items' => $group->sortBy($order)->map(fn ($check) => $check + ['class' => $style($check)[0], 'icon' => $style($check)[1]])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function advice(array $advice): array
    {
        return array_map(fn ($item) => [
            'class' => self::ADVICE_STYLES[$item['level']][0],
            'icon' => self::ADVICE_STYLES[$item['level']][1],
            'text' => $item['text'],
            'link' => $item['action'] ? self::ADVICE_LINKS[$item['action']] : null,
        ], $advice);
    }

    private function actions(array $status): array
    {
        $pending = $status['database']['pending'];
        $production = $status['app']['production'];
        $enabledActions = config('opconsole.actions', []);
        $last = function (string $action) use ($status) {
            $run = $status['lastRuns'][$action] ?? null;

            return $run
                ? ['ok' => $run['ok'], 'text' => 'Last run '.Carbon::parse($run['at'])->locale('en')->diffForHumans().' — '.$run['by'].($run['ok'] ? '' : ' (failed)')]
                : null;
        };

        $all = [
            'clear' => [
                'icon' => 'uil-trash-alt', 'title' => 'Clear caches', 'button' => 'Clear now', 'style' => 'btn-soft-primary',
                'text' => 'Clears cached configuration, routes, views, events, and application data. Sessions are unaffected.',
                'confirm' => 'Clear all caches? Initial page loads may be slightly slower.',
                'enabled' => (bool) ($enabledActions['clear'] ?? false), 'pending' => [], 'last' => $last('clear'),
            ],
            'optimize' => [
                'icon' => 'uil-rocket', 'title' => 'Optimize performance', 'button' => 'Optimize', 'style' => 'btn-soft-success',
                'text' => 'Caches configuration, routes, and views for production. Clear caches after changing .env.',
                'confirm' => $production ? 'Cache configuration, routes, and views?' : 'Development environment: after optimizing, clear caches to apply .env and route changes. Continue?',
                'enabled' => (bool) ($enabledActions['optimize'] ?? false), 'pending' => [], 'last' => $last('optimize'),
            ],
            'migrate' => [
                'icon' => 'uil-database', 'title' => 'Run migrations', 'button' => $pending ? 'Run '.count($pending) : 'Nothing pending', 'style' => 'btn-primary',
                'text' => 'Applies pending database migrations. Runs forward only, without resets or rollbacks.',
                'confirm' => 'Run '.count($pending).' database migrations? Back up the database first.',
                'enabled' => (bool) ($enabledActions['migrate'] ?? false) && $status['database']['ok'] && $pending, 'pending' => $pending, 'last' => $last('migrate'),
            ],
        ];

        return array_filter($all, fn ($action, $key) => $enabledActions[$key] ?? false, ARRAY_FILTER_USE_BOTH);
    }

    /** File cards; every file except the newest (still being written) can be deleted. */
    private function files(array $files, ?array $log): array
    {
        return array_map(fn ($file, $i) => [
            'name' => $file['name'],
            'meta' => Number::fileSize($file['size']).' · '.$file['modified']->locale('en')->diffForHumans(),
            'url' => route($this->routeName('index'), ['log' => $file['name']]).'#logs',
            'active' => $file['name'] === ($log['name'] ?? null),
            'deletable' => $i > 0 && config('opconsole.logs.allow_delete', true),
        ], $files, array_keys($files));
    }

    private function log(array $log, string $currentEnv): array
    {
        $url = fn (array $params = []) => route($this->routeName('index'), array_filter($params + [
            'log' => $log['name'], 'env' => $log['env'] ?? 'all', 'level' => $log['level'], 'q' => $log['search'],
        ])).'#logs';

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

        return [
            'name' => $log['name'],
            'env' => $log['env'] ?? 'all',
            'level' => $log['level'],
            'search' => $log['search'],
            'envChips' => $envChips,
            'levelChips' => $levelChips,
            'clearSearchUrl' => $url(['q' => null]),
            'downloadUrl' => config('opconsole.logs.allow_download', true) ? route($this->routeName('log'), $log['name']) : null,
            'summary' => 'Showing '.count($log['entries']).' of '.$log['shown'].' entries'.($log['shown'] !== $log['total'] ? ' (total: '.$log['total'].')' : ''),
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

    /** Escaped text with the search term wrapped in <mark>. Safe to print unescaped.
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
