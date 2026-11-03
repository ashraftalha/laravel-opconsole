@extends($layout ?? 'opconsole::layout')

{{-- Element IDs are prefixed or scoped to avoid colliding with host layout scripts when embedded via config('opconsole.layout'). --}}
@section('title', 'OpConsole')

@if ($layout)
    @push('styles')
        <link href="{{ $assets['css'] }}" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ $assets['js'] }}" defer></script>
    @endpush
@endif

@section('content')
    <div class="oc-embedded-shell">
        @if ($layout)
            @include('opconsole::partials.flash')
        @endif

        <div class="oc-sr-only" id="opconsole-live-region" aria-live="polite" aria-atomic="true"></div>

        <header class="oc-header">
            <div>
                @if ($backUrl)
                    <a href="{{ $backUrl }}" class="oc-back-link">
                        @include('opconsole::partials.icon', ['name' => 'arrow-left'])
                        <span>{{ $backLabel }}</span>
                    </a>
                @endif
                <div class="oc-title-row">
                    <h1 class="oc-title">OpConsole</h1>
                    <span @class(['oc-env-badge', 'is-prod' => $environment['production'], 'is-debug' => $environment['debug'] && $environment['production']])>
                        {{ $environment['name'] }}@if ($environment['debug']) · debug @endif
                    </span>
                </div>
                <p class="oc-subtitle">Monitor. Diagnose. Maintain.</p>
            </div>
            <a href="{{ $indexUrl }}" class="oc-btn oc-btn-soft-primary">
                @include('opconsole::partials.icon', ['name' => 'refresh'])
                <span>Refresh status</span>
            </a>
        </header>

        <div class="oc-workspace">
            {{-- Status at a glance --}}
            <section aria-label="Operational summary" class="oc-grid-3">
                @foreach ($tiles as $tile)
                    <div>
                        @if ($tile['link'])<a href="{{ $tile['link'] }}" class="oc-tile-link">@endif
                            <div class="oc-tile {{ $tile['tone'] }}">
                                <span class="oc-tile-icon">@include('opconsole::partials.icon', ['name' => $tile['icon']])</span>
                                <div>
                                    <strong>{{ $tile['value'] }}</strong>
                                    <span>{{ $tile['label'] }} · <bdi>{{ $tile['sub'] }}</bdi></span>
                                </div>
                            </div>
                        @if ($tile['link'])</a>@endif
                    </div>
                @endforeach
            </section>

            {{-- What to do next, most urgent first --}}
            <section aria-label="Operational recommendations" class="oc-advice-list">
                @forelse ($advice as $item)
                    <div class="oc-advice {{ $item['class'] }}">
                        @include('opconsole::partials.icon', ['name' => $item['icon']])
                        <span>{{ $item['text'] }}</span>
                        @if ($item['link'])
                            <a href="{{ $item['link'][0] }}" class="oc-advice-link">
                                <span>{{ $item['link'][1] }}</span>
                                @include('opconsole::partials.icon', ['name' => 'arrow-right'])
                            </a>
                        @endif
                    </div>
                @empty
                    @if ($checks['tone'] === 'is-ok')
                        <div class="oc-advice oc-advice-ok">
                            @include('opconsole::partials.icon', ['name' => 'check-circle'])
                            <span>Everything verified looks healthy. No immediate maintenance action is needed.</span>
                        </div>
                    @endif
                @endforelse
            </section>

            {{-- Section tabs: progressive enhancement. Without JavaScript every panel stays visible in page order. --}}
            @php
                $hasActionsTab = count($actions) > 0 || $output;
                $healthProblems = collect($checks['groups'])->flatMap(fn ($group) => $group['items'])
                    ->whereIn('status', ['warning', 'critical'])->count();
                $healthCritical = collect($checks['groups'])->flatMap(fn ($group) => $group['items'])
                    ->where('status', 'critical')->count();
                $tabs = array_filter([
                    'actions' => $hasActionsTab ? ['label' => 'Actions', 'icon' => 'terminal', 'hash' => 'actions', 'badge' => null, 'tone' => ''] : null,
                    'health' => ['label' => 'Health', 'icon' => 'heart-rate', 'hash' => 'checks', 'badge' => $healthProblems ?: null, 'tone' => $healthCritical ? 'is-bad' : ($healthProblems ? 'is-warning' : '')],
                    'history' => ['label' => 'History', 'icon' => 'clock', 'hash' => 'history', 'badge' => $history['available'] && count($history['items']) ? count($history['items']) : null, 'tone' => 'is-neutral'],
                    'logs' => ['label' => 'Logs', 'icon' => 'file-text', 'hash' => 'logs', 'badge' => null, 'tone' => ''],
                    'system' => ['label' => 'System', 'icon' => 'info', 'hash' => 'system-info', 'badge' => null, 'tone' => ''],
                ]);
                $defaultTab = $output ? 'actions' : ($healthCritical ? 'health' : array_key_first($tabs));
            @endphp
            <nav class="oc-tabs" role="tablist" aria-label="OpConsole sections" data-oc-tabs data-oc-default="{{ $defaultTab }}" hidden>
                @foreach ($tabs as $key => $tab)
                    <button type="button" class="oc-tab" role="tab" id="oc-tab-{{ $key }}" aria-controls="oc-panel-{{ $key }}" aria-selected="false" tabindex="-1" data-oc-tab="{{ $key }}" data-oc-hash="{{ $tab['hash'] }}">
                        @include('opconsole::partials.icon', ['name' => $tab['icon']])
                        <span>{{ $tab['label'] }}</span>
                        @if ($tab['badge'])
                            <span class="oc-tab-badge {{ $tab['tone'] }}">{{ $tab['badge'] }}</span>
                        @endif
                    </button>
                @endforeach
            </nav>

            @if ($hasActionsTab)
            <div class="oc-tab-panel" id="oc-panel-actions" role="tabpanel" aria-labelledby="oc-tab-actions" data-oc-panel="actions">
            @endif
            {{-- Maintenance actions --}}
            @if (count($actions))
                <section aria-label="Maintenance operations" class="oc-grid-3">
                    @foreach ($actions as $key => $action)
                        <article @class(['oc-action', 'is-high-risk' => $action['risk'] === 'high']) id="action-{{ $key }}">
                            <div class="oc-action-top">
                                <span class="oc-action-icon">@include('opconsole::partials.icon', ['name' => $action['icon']])</span>
                                <span @class(['oc-risk-badge', 'is-high' => $action['risk'] === 'high', 'is-medium' => $action['risk'] === 'medium'])>{{ $action['riskLabel'] }}</span>
                            </div>
                            <h2>{{ $action['title'] }}</h2>
                            <code class="oc-command-pill" dir="ltr">{{ $action['command'] }}</code>
                            <p>{{ $action['text'] }}</p>

                            @if ($action['pending'])
                                <ul class="oc-migrations" dir="ltr" aria-label="Pending migrations">
                                    @foreach (array_slice($action['pending'], 0, 8) as $migration)
                                        <li>{{ $migration }}</li>
                                    @endforeach
                                    @if (count($action['pending']) > 8)
                                        <li class="oc-migrations-more">+{{ count($action['pending']) - 8 }} more pending</li>
                                    @endif
                                </ul>
                            @endif

                            @if ($action['disabledReason'])
                                <small class="oc-action-disabled-note">{{ $action['disabledReason'] }}</small>
                            @endif

                            @if ($action['last'])
                                <small class="oc-action-last">
                                    @include('opconsole::partials.icon', ['name' => $action['last']['ok'] ? 'check' : 'x', 'class' => $action['last']['ok'] ? 'oc-icon oc-text-ok' : 'oc-icon oc-text-bad'])
                                    <span>{{ $action['last']['text'] }}</span>
                                </small>
                            @endif

                            <form
                                method="POST"
                                action="{{ $action['url'] }}"
                                data-confirm="{{ $action['confirm'] }}"
                                data-confirm-title="{{ $action['confirmTitle'] }}"
                                data-confirm-accept="{{ $action['confirmAccept'] }}"
                                data-confirm-command="{{ $action['command'] }}"
                                data-confirm-risk="{{ $action['risk'] }}"
                                data-confirm-risk-label="{{ $action['riskLabel'] }}"
                            >
                                @csrf
                                <button type="submit" class="oc-btn {{ $action['style'] }} oc-w-full" @disabled(! $action['enabled'])>
                                    @include('opconsole::partials.icon', ['name' => $action['icon']])
                                    <span data-oc-btn-label>{{ $action['button'] }}</span>
                                </button>
                            </form>
                        </article>
                    @endforeach
                </section>
            @endif

            {{-- Output of the last action --}}
            @if ($output)
                <section class="oc-section" aria-label="Last operation output">
                    <div class="oc-section-head">
                        <b>@include('opconsole::partials.icon', ['name' => 'terminal'])</b>
                        <div>
                            <h2>Output: {{ $output['title'] }}</h2>
                            <small>Captured console output from the last operation</small>
                        </div>
                        <span @class(['oc-status-pill', 'is-ok' => $output['ok'], 'is-bad' => ! $output['ok'], 'oc-ms-auto' => true])>
                            {{ $output['ok'] ? 'Succeeded' : 'Failed' }}
                        </span>
                        <button type="button" class="oc-btn oc-btn-sm oc-btn-soft-secondary" data-copy="{{ $output['text'] }}">
                            @include('opconsole::partials.icon', ['name' => 'copy'])
                            <span data-oc-copy-label>Copy</span>
                        </button>
                    </div>
                    <div class="oc-section-body">
                        <pre class="oc-output" dir="ltr">{{ $output['text'] }}</pre>
                    </div>
                </section>
            @endif

            @if ($hasActionsTab)
            </div>
            @endif

            <div class="oc-tab-panel" id="oc-panel-health" role="tabpanel" aria-labelledby="oc-tab-health" data-oc-panel="health">
            {{-- Health checks: critical problems first, auto-expanded when problems exist --}}
            <details class="oc-section oc-check-details" id="checks"{{ $checks['hasProblems'] ? ' open' : '' }}>
                <summary class="oc-section-head oc-check-toggle">
                    <b>@include('opconsole::partials.icon', ['name' => 'heart-rate'])</b>
                    <div>
                        <h2>Health checks</h2>
                        <small>
                            Application and environment diagnostics, critical issues listed first
                            @if ($checks['lastChecked'])
                                · <time datetime="{{ $checks['lastChecked']['iso'] }}">{{ $checks['lastChecked']['text'] }}</time>
                            @endif
                        </small>
                    </div>
                    <span class="oc-checks-summary {{ $checks['tone'] }} oc-ms-auto">{{ $checks['summary'] }}</span>
                    <span class="oc-check-chevron">@include('opconsole::partials.icon', ['name' => 'chevron-down'])</span>
                </summary>
                <div class="oc-section-body">
                    @foreach ($checks['groups'] as $group)
                        <div class="oc-check-group">
                            <h3 class="oc-check-group-title">
                                <span>{{ $group['name'] }}</span>
                                <small>{{ $group['passed'] }}/{{ $group['total'] }} healthy @if ($group['unknown']) · {{ $group['unknown'] }} unknown @endif</small>
                            </h3>
                            <ul class="oc-checks oc-checks-grid">
                                @foreach ($group['items'] as $check)
                                    <li class="{{ $check['class'] }}">
                                        <span class="oc-check-icon">@include('opconsole::partials.icon', ['name' => $check['icon']])</span>
                                        <div>
                                            <div class="oc-check-title-row">
                                                <strong>{{ $check['label'] }}</strong>
                                                @if ($check['status'] !== 'healthy')
                                                    <span class="oc-status-pill {{ $check['class'] }}">{{ $check['badge'] }}</span>
                                                @endif
                                            </div>
                                            <small>{{ $check['hint'] }}</small>
                                            @if ($check['value'])<code class="oc-check-value" dir="ltr">{{ $check['value'] }}</code>@endif
                                            @if ($check['fix'] && (! $check['ok'] || $check['status'] === 'unknown'))
                                                <span class="oc-check-fix"><span>Fix:</span> <code dir="ltr">{{ $check['fix'] }}</code></span>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </details>

            </div>

            <div class="oc-tab-panel" id="oc-panel-history" role="tabpanel" aria-labelledby="oc-tab-history" data-oc-panel="history">
            {{-- Audit history --}}
            <details class="oc-section oc-history-details" id="history"{{ count($history['items']) > 0 ? ' open' : '' }}>
                <summary class="oc-section-head oc-check-toggle">
                    <b>@include('opconsole::partials.icon', ['name' => 'clock'])</b>
                    <div>
                        <h2>Operation history</h2>
                        <small>Recent audited maintenance actions and log operations</small>
                    </div>
                    <span class="oc-checks-summary is-neutral oc-ms-auto">
                        @if (! $history['available'])
                            Audit table not migrated
                        @else
                            {{ count($history['items']) }} recent
                        @endif
                    </span>
                    <span class="oc-check-chevron">@include('opconsole::partials.icon', ['name' => 'chevron-down'])</span>
                </summary>
                <div class="oc-section-body">
                    @if (! $history['available'])
                        @include('opconsole::partials.empty-state', ['message' => 'The opconsole_runs audit table has not been migrated yet. Run migrations to persist operation history.'])
                    @elseif (! count($history['items']))
                        @include('opconsole::partials.empty-state', ['message' => 'No operations have been recorded yet.'])
                    @else
                        <div class="oc-table-wrap">
                            <table class="oc-table">
                                <thead>
                                    <tr>
                                        <th scope="col">Operation</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Actor</th>
                                        <th scope="col">Started</th>
                                        <th scope="col">Duration</th>
                                        <th scope="col">Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($history['items'] as $run)
                                        <tr>
                                            <td>
                                                <strong>{{ $run['actionLabel'] }}</strong>
                                                <code class="oc-inline-code" dir="ltr">{{ $run['command'] }}</code>
                                            </td>
                                            <td>
                                                <span class="oc-status-pill {{ $run['statusClass'] }}" data-run-status="{{ $run['status'] }}">{{ $run['statusLabel'] }}</span>
                                            </td>
                                            <td><bdi>{{ $run['actor'] }}</bdi></td>
                                            <td>
                                                @if ($run['startedIso'])
                                                    <time datetime="{{ $run['startedIso'] }}" title="{{ $run['startedExact'] }}">{{ $run['startedRelative'] }}</time>
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td dir="ltr">{{ $run['duration'] }}</td>
                                            <td class="oc-table-details"><bdi>{{ $run['details'] }}</bdi></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </details>

            </div>

            <div class="oc-tab-panel" id="oc-panel-system" role="tabpanel" aria-labelledby="oc-tab-system" data-oc-panel="system">
            {{-- System info, grouped; copyable for support --}}
            <details class="oc-section oc-system-info" id="system-info">
                <summary class="oc-system-toggle">
                    <span class="oc-inline-flex">@include('opconsole::partials.icon', ['name' => 'info']) <span>System information</span></span>
                    <span class="oc-inline-flex"><span>View details</span> <span class="oc-check-chevron">@include('opconsole::partials.icon', ['name' => 'chevron-down'])</span></span>
                </summary>
                <div class="oc-section-head">
                    <b>@include('opconsole::partials.icon', ['name' => 'info'])</b>
                    <div><small>Runtime and service configuration summary (contains no raw secrets)</small></div>
                    <button type="button" class="oc-btn oc-btn-sm oc-btn-soft-primary oc-ms-auto" data-copy="{{ $infoText }}" title="Copy system summary">
                        @include('opconsole::partials.icon', ['name' => 'copy'])
                        <span data-oc-copy-label>Copy</span>
                    </button>
                </div>
                <div class="oc-section-body oc-info-grid">
                    @foreach ($info as $group => $rows)
                        <div>
                            <h3 class="oc-info-group">{{ $group }}</h3>
                            <dl class="oc-info">
                                @foreach ($rows as $label => $value)
                                    <div><dt>{{ $label }}</dt><dd><bdi>{{ $value }}</bdi></dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                    @if ($dbError)
                        <small class="oc-db-error" dir="ltr">{{ $dbError }}</small>
                    @endif
                </div>
            </details>

            </div>

            <div class="oc-tab-panel" id="oc-panel-logs" role="tabpanel" aria-labelledby="oc-tab-logs" data-oc-panel="logs">
            {{-- Log files --}}
            <section class="oc-section" id="logs" aria-labelledby="oc-logs-heading">
                <div class="oc-section-head">
                    <b>@include('opconsole::partials.icon', ['name' => 'file-text'])</b>
                    <div>
                        <h2 id="oc-logs-heading">System logs</h2>
                        <small>Application log entries, newest first</small>
                    </div>
                </div>
                <div class="oc-section-body">
                    @if (! $log)
                        @include('opconsole::partials.empty-state', ['message' => 'No log files yet.'])
                    @else
                        {{-- File picker --}}
                        <nav class="oc-log-files" aria-label="Log files">
                            @foreach ($logFiles as $file)
                                <div @class(['oc-log-file', 'is-active' => $file['active']])>
                                    <a href="{{ $file['url'] }}" class="oc-file-link" aria-label="Open {{ $file['name'] }}" @if ($file['active']) aria-current="true" @endif>
                                        @include('opconsole::partials.icon', ['name' => 'file'])
                                    </a>
                                    <span dir="ltr">{{ $file['name'] }}</span>
                                    <small>{{ $file['meta'] }}</small>
                                    @if ($file['deletable'])
                                        <form
                                            method="POST"
                                            action="{{ $file['deleteUrl'] }}"
                                            class="oc-log-delete"
                                            data-confirm="Permanently delete rotated log file {{ $file['name'] }}? This action cannot be undone."
                                            data-confirm-title="Delete log file"
                                            data-confirm-accept="Delete file"
                                            data-confirm-risk="high"
                                            data-confirm-risk-label="High risk · Permanent deletion"
                                        >
                                            @csrf @method('DELETE')
                                            <button type="submit" aria-label="Delete {{ $file['name'] }}" title="Delete {{ $file['name'] }}">
                                                @include('opconsole::partials.icon', ['name' => 'trash'])
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </nav>

                        @if ($log['envChips'])
                            <div class="oc-log-toolbar">
                                <div class="oc-log-levels" role="group" aria-label="Filter by environment">
                                    @foreach ($log['envChips'] as $chip)
                                        <a href="{{ $chip['url'] }}" @class(['oc-chip', 'is-active' => $chip['active']]) @if ($chip['active']) aria-current="true" @endif>
                                            <span>{{ $chip['label'] }}</span> <b>{{ $chip['count'] }}</b>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="oc-log-toolbar">
                            <div class="oc-log-levels" role="group" aria-label="Filter by log level">
                                @foreach ($log['levelChips'] as $chip)
                                    <a href="{{ $chip['url'] }}" @class(['oc-chip', $chip['style'], 'is-active' => $chip['active']]) @if ($chip['active']) aria-current="true" @endif>
                                        <span>{{ $chip['label'] }}</span> <b>{{ $chip['count'] }}</b>
                                    </a>
                                @endforeach
                            </div>
                            <form method="GET" action="{{ $indexUrl }}#logs" class="oc-log-search" role="search">
                                <input type="hidden" name="log" value="{{ $log['name'] }}">
                                <input type="hidden" name="env" value="{{ $log['env'] }}">
                                @if ($log['level'])<input type="hidden" name="level" value="{{ $log['level'] }}">@endif
                                <label for="oc-log-search-input" class="oc-sr-only">Search logs</label>
                                <input id="oc-log-search-input" type="search" aria-label="Search logs" name="q" value="{{ $log['search'] }}" class="oc-input" placeholder="Search messages and stack traces…" maxlength="200" dir="auto">
                                <button type="submit" class="oc-btn oc-btn-soft-primary" aria-label="Search" title="Search">
                                    @include('opconsole::partials.icon', ['name' => 'search'])
                                </button>
                                @if ($log['search'] !== '')
                                    <a href="{{ $log['clearSearchUrl'] }}" class="oc-btn oc-btn-soft-secondary" aria-label="Clear search" title="Clear search">
                                        @include('opconsole::partials.icon', ['name' => 'x'])
                                    </a>
                                @endif
                            </form>
                            @if ($log['downloadUrl'])
                                <a href="{{ $log['downloadUrl'] }}" class="oc-btn oc-btn-soft-secondary">
                                    @include('opconsole::partials.icon', ['name' => 'download'])
                                    <span>Download file</span>
                                </a>
                            @endif
                        </div>

                        <p class="oc-log-summary">
                            <span>{{ $log['summary'] }}</span>
                            @if ($log['truncated'])
                                <span>· Showing the latest 2 MB tail only. Download the file to inspect older entries.</span>
                            @endif
                            @if ($log['resetFiltersUrl'])
                                · <a href="{{ $log['resetFiltersUrl'] }}" class="oc-inline-link">Reset filters</a>
                            @endif
                        </p>

                        @if ($log['groups'])
                            <details class="oc-log-groups">
                                <summary>@include('opconsole::partials.icon', ['name' => 'layers']) <span>Most frequent repeated entries</span></summary>
                                @foreach ($log['groups'] as $group)
                                    <div class="oc-log-group {{ $group['style'] }}">
                                        <span class="oc-log-count">×{{ $group['count'] }}</span>
                                        <span class="oc-log-message" dir="ltr">{{ $group['message'] }}</span>
                                        <span class="oc-log-meta" dir="ltr">{{ $group['last'] }}</span>
                                    </div>
                                @endforeach
                            </details>
                        @endif

                        {{-- Entries (messageHtml / detailsHtml are pre-escaped by PagePresenter, with <mark> around search matches) --}}
                        @forelse ($log['entries'] as $entry)
                            <details class="oc-log-entry {{ $entry['style'] }}">
                                <summary>
                                    <span class="oc-log-level">{{ $entry['level'] }}</span>
                                    <span class="oc-log-message" dir="ltr">{!! $entry['messageHtml'] !!}</span>
                                    <span class="oc-log-meta" dir="ltr">{{ $entry['meta'] }}</span>
                                </summary>
                                <div class="oc-log-body">
                                    <button type="button" class="oc-btn oc-btn-sm oc-btn-soft-secondary" data-copy="{{ $entry['copy'] }}" title="Copy log entry">
                                        @include('opconsole::partials.icon', ['name' => 'copy'])
                                        <span data-oc-copy-label>Copy entry</span>
                                    </button>
                                    @if ($entry['detailsHtml'])
                                        <pre class="oc-output" dir="ltr">{!! $entry['detailsHtml'] !!}</pre>
                                    @endif
                                </div>
                            </details>
                        @empty
                            @include('opconsole::partials.empty-state', [
                                'message' => $log['emptyMessage'],
                                'actionUrl' => $log['resetFiltersUrl'],
                                'actionLabel' => $log['resetFiltersUrl'] ? 'Reset filters' : null,
                            ])
                        @endforelse

                        @if ($log['moreUrl'])
                            <a href="{{ $log['moreUrl'] }}" class="oc-btn oc-btn-soft-primary oc-w-full oc-mt-2">
                                @include('opconsole::partials.icon', ['name' => 'chevron-down'])
                                <span>Show more entries</span>
                            </a>
                        @endif
                    @endif
                </div>
            </section>
            </div>
        </div>

        @if ($layout)
            @include('opconsole::partials.confirm-modal')
        @endif
    </div>
@endsection
