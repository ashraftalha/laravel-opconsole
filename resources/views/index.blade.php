@extends($layout ?? 'opconsole::layout')

{{-- Element IDs are prefixed or scoped to avoid colliding with host layout scripts when embedded via config('opconsole.layout'). --}}
@section('title', 'OpConsole')

@if ($layout)
    @push('styles')
        @include('opconsole::partials.fonts')
        <link href="{{ $assets['css'] }}" rel="stylesheet">
    @endpush
    @push('scripts')
        <script src="{{ $assets['js'] }}" defer></script>
    @endpush
@endif

@section('content')
    <div class="oc-embedded-shell" data-oc-theme="{{ $theme }}">
        @if ($layout)
            @include('opconsole::partials.flash')
        @endif

        <div class="oc-sr-only" id="opconsole-live-region" aria-live="polite" aria-atomic="true"></div>

        @php($ocHealthIcon = match ($overview['tone']) { 'is-bad' => 'x-circle', 'is-warning' => 'alert-circle', 'is-unknown' => 'help-circle', default => 'check-circle' })
        @php($ocScore = $overview['score'])
        @php($ocToneOf = fn (string $status) => match ($status) { 'critical' => 'is-bad', 'warning' => 'is-warning', 'healthy' => 'is-ok', default => 'is-unknown' })

        {{-- ================= Header ================= --}}
        <header @class(['oc-header', 'is-production' => $environment['production']])>
            @if ($backUrl)
                <a href="{{ $backUrl }}" class="oc-back-link">
                    @include('opconsole::partials.icon', ['name' => 'arrow-left'])
                    <span>{{ $backLabel }}</span>
                </a>
            @endif
            <div class="oc-header-row">
                <div class="oc-header-id">
                    <div class="oc-score {{ $overview['tone'] }}" role="img" aria-label="{{ $ocScore === null ? 'Health score unavailable' : 'Health score '.$ocScore.' out of 100' }}">
                        <svg class="oc-score-ring" viewBox="0 0 40 40" aria-hidden="true" focusable="false"><circle class="oc-score-track" cx="20" cy="20" r="17" pathLength="100" /><circle class="oc-score-fill" cx="20" cy="20" r="17" pathLength="100" stroke-dasharray="{{ $ocScore ?? 0 }} 100" transform="rotate(-90 20 20)" /></svg>
                        <span class="oc-score-num">{{ $ocScore ?? '–' }}</span>
                    </div>
                    <div class="oc-header-text">
                        <div class="oc-title-row">
                            @include('opconsole::partials.logo', ['variant' => 'full', 'class' => 'oc-header-logo'])
                            <span class="oc-title-sep" aria-hidden="true">/</span>
                            <h1 class="oc-title">{{ $overview['appName'] }}</h1>
                            <span @class(['oc-env-badge', 'is-prod' => $environment['production'], 'is-debug' => $environment['debug'] && $environment['production']])>
                                <span class="oc-sr-only">Environment: </span>{{ $environment['name'] }}@if ($environment['debug']) · debug @endif
                            </span>
                        </div>
                        <ul class="oc-header-meta" aria-label="Application summary">
                            <li class="oc-health-pill {{ $overview['tone'] }}">
                                @include('opconsole::partials.icon', ['name' => $ocHealthIcon])
                                <span>{{ $overview['state'] }}</span>
                            </li>
                            @if (! empty($info['Application']['Laravel']))<li><span class="oc-meta-key">Laravel</span> <bdi>{{ $info['Application']['Laravel'] }}</bdi></li>@endif
                            @if (! empty($info['PHP']['Version']))<li><span class="oc-meta-key">PHP</span> <bdi>{{ $info['PHP']['Version'] }}</bdi></li>@endif
                            @if ($overview['lastChecked'])<li><time datetime="{{ $overview['lastChecked']['iso'] }}" data-oc-ago>{{ $overview['lastChecked']['text'] }}</time></li>@endif
                        </ul>
                    </div>
                </div>
                <div class="oc-header-actions">
                    <button type="button" class="oc-btn oc-btn-secondary oc-palette-btn" data-oc-palette-open hidden aria-label="Search or jump to (Ctrl K)" title="Search or jump to">
                        @include('opconsole::partials.icon', ['name' => 'search'])
                        <span class="oc-palette-btn-label">Search or jump to…</span>
                        <kbd class="oc-kbd" data-oc-palette-kbd>Ctrl K</kbd>
                    </button>
                    @if ($themeToggle)
                        <button type="button" class="oc-btn oc-btn-secondary oc-theme-toggle" data-oc-theme-toggle hidden aria-label="Switch theme" title="Switch theme">
                            <span class="oc-theme-icon-dark">@include('opconsole::partials.icon', ['name' => 'moon'])</span>
                            <span class="oc-theme-icon-light">@include('opconsole::partials.icon', ['name' => 'sun'])</span>
                        </button>
                    @endif
                    <a href="{{ $refreshUrl }}" class="oc-btn oc-btn-primary" data-oc-refresh>
                        @include('opconsole::partials.icon', ['name' => 'refresh'])
                        <span>Refresh status</span>
                    </a>
                </div>
            </div>
        </header>

        {{-- ================= Tabs ================= --}}
        {{-- Progressive enhancement: without JavaScript every panel stays visible in page order. --}}
        @php($hasOperationsTab = isset($tabs['items']['operations']))
        <div class="oc-tabs-wrap" data-oc-tabs-wrap hidden>
            <nav class="oc-tabs" role="tablist" aria-label="{{ __('opconsole::opconsole.tabs_label') }}" data-oc-tabs data-oc-default="{{ $tabs['default'] }}">
                @foreach ($tabs['items'] as $key => $tab)
                    <button type="button" class="oc-tab" role="tab" id="oc-tab-{{ $key }}" aria-controls="oc-panel-{{ $key }}" aria-selected="false" tabindex="-1" data-oc-tab="{{ $key }}" data-oc-hash="{{ $tab['hash'] }}">
                        @include('opconsole::partials.icon', ['name' => $tab['icon']])
                        <span>{{ $tab['label'] }}</span>
                        @if ($tab['badge'] !== null)
                            <span class="oc-tab-badge {{ $tab['tone'] }}" aria-hidden="true" title="{{ $tab['badgeLabel'] }}">{{ $tab['badge'] }}</span>
                            <span class="oc-sr-only">({{ $tab['badgeLabel'] }})</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        </div>

        <div class="oc-workspace">

            {{-- ================= Overview ================= --}}
            <div class="oc-tab-panel" id="oc-panel-overview" role="tabpanel" aria-labelledby="oc-tab-overview" data-oc-panel="overview">
                <section class="oc-hero {{ $overview['tone'] }}" id="overview" aria-labelledby="oc-overview-title">
                    <div class="oc-hero-main">
                        <span class="oc-eyebrow">Application overview</span>
                        <h2 id="oc-overview-title">
                            @include('opconsole::partials.icon', ['name' => $ocHealthIcon])
                            <span>{{ $overview['state'] }}</span>
                        </h2>
                        <p>{{ $overview['summary'] }}</p>
                    </div>
                    <div class="oc-hero-counts" aria-label="Health check totals">
                        <a href="#checks" class="oc-count is-bad"><strong>{{ $overview['critical'] }}</strong><span>Critical</span></a>
                        <a href="#checks" class="oc-count is-warning"><strong>{{ $overview['warnings'] }}</strong><span>Warnings</span></a>
                        <a href="#checks" class="oc-count is-unknown"><strong>{{ $overview['unknown'] }}</strong><span>Unverified</span></a>
                        <a href="#checks" class="oc-count is-ok"><strong>{{ $overview['healthy'] }}</strong><span>Healthy</span></a>
                    </div>
                    @if ($overview['total'])
                        @include('opconsole::partials.segbar', ['counts' => $overview, 'label' => null])
                    @endif
                </section>

                {{-- Status at a glance --}}
                <section aria-label="Operational summary" class="oc-kpis">
                    @foreach ($tiles as $tile)
                        @if ($tile['link'])<a href="{{ $tile['link'] }}" class="oc-kpi {{ $tile['tone'] }}">@else<div class="oc-kpi {{ $tile['tone'] }}">@endif
                            <span class="oc-kpi-label">
                                <span class="oc-kpi-icon">@include('opconsole::partials.icon', ['name' => $tile['icon']])</span>
                                <span>{{ $tile['label'] }}</span>
                                <span class="oc-dot {{ $tile['tone'] }}" aria-hidden="true"></span>
                            </span>
                            <strong>{{ $tile['value'] }}</strong>
                            <span class="oc-kpi-sub"><bdi>{{ $tile['sub'] }}</bdi></span>
                        @if ($tile['link'])</a>@else</div>@endif
                    @endforeach
                </section>

                @if ($advice)
                    <section class="oc-advice-list" aria-label="Operational recommendations">
                        @foreach ($advice as $item)
                            <div class="oc-advice {{ $item['class'] }}">
                                @include('opconsole::partials.icon', ['name' => $item['icon']])
                                <span>{{ $item['text'] }}</span>
                                @if ($item['link'])
                                    <a href="{{ $item['link'][0] }}" class="oc-advice-link"><span>{{ $item['link'][1] }}</span>@include('opconsole::partials.icon', ['name' => 'arrow-right'])</a>
                                @endif
                            </div>
                        @endforeach
                    </section>
                @endif

                <div class="oc-split">
                    <section class="oc-card" aria-labelledby="oc-attention-title">
                        <div class="oc-card-head">
                            <span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => $overview['critical'] ? 'alert-octagon' : 'activity'])</span>
                            <div><h2 id="oc-attention-title">Attention needed</h2><small>Verified issues first, then checks that need verification</small></div>
                        </div>
                        @if ($overview['attention'])
                            <ul class="oc-rows">
                                @foreach ($overview['attention'] as $item)
                                    <li class="oc-row {{ $item['tone'] }}">
                                        <span class="oc-stripe" aria-hidden="true"></span>
                                        <div class="oc-row-main">
                                            <div class="oc-row-title"><strong>{{ $item['label'] }}</strong><span class="oc-status-pill {{ $item['tone'] }}">{{ $item['status'] }}</span></div>
                                            <small>{{ $item['group'] }} · {{ $item['hint'] }}</small>
                                            @if ($item['fix'])
                                                <div class="oc-fix"><code dir="ltr">{{ $item['fix'] }}</code><button type="button" class="oc-fix-copy" data-copy="{{ $item['fix'] }}" aria-label="Copy fix for {{ $item['label'] }}">@include('opconsole::partials.icon', ['name' => 'copy'])<span data-oc-copy-label>Copy</span></button></div>
                                            @endif
                                        </div>
                                        <a href="{{ $item['url'] }}" class="oc-btn oc-btn-sm oc-btn-secondary" aria-label="Review {{ $item['label'] }}">Review</a>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($overview['moreAttention'])<div class="oc-card-foot"><a class="oc-inline-link" href="#checks">View {{ $overview['moreAttention'] }} more verified issue(s)</a></div>@endif
                        @else
                            <div class="oc-inline-empty">
                                @include('opconsole::partials.icon', ['name' => $overview['unknown'] ? 'help-circle' : 'check-circle'])
                                <p>{{ $overview['unknown'] ? 'No verified failures. Some checks are unverified; review them before relying on a fully healthy result.' : ($overview['total'] ? 'No verified issues need attention.' : 'Health information is unavailable right now.') }}</p>
                            </div>
                        @endif
                        @if ($overview['unknownItems'])
                            <details class="oc-unverified">
                                <summary>@include('opconsole::partials.icon', ['name' => 'help-circle']){{ $overview['unknown'] }} unverified {{ $overview['unknown'] === 1 ? 'check' : 'checks' }}</summary>
                                <ul>@foreach ($overview['unknownItems'] as $item)<li><strong>{{ $item['label'] }}</strong><small>{{ $item['group'] }} · {{ $item['hint'] }}</small></li>@endforeach</ul>
                                <a class="oc-inline-link" href="#checks">Review health checks</a>
                            </details>
                        @endif
                    </section>

                    <div class="oc-stack">
                        <section class="oc-card" aria-labelledby="oc-runtime-title">
                            <div class="oc-card-head">
                                <span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'activity'])</span>
                                <div><h2 id="oc-runtime-title">Runtime status</h2><small>Scheduler, queue worker, and availability</small></div>
                            </div>
                            <div class="oc-runtime">
                                @foreach (array_filter([['Scheduler', 'clock', $overview['scheduler']], ['Queue worker', 'layers', $overview['queue']], $overview['octane'] ? ['Octane server', 'zap', $overview['octane']] : null]) as [$label, $icon, $service])
                                    <a href="#checks" class="oc-runtime-row {{ $ocToneOf($service['status']) }}">
                                        <span class="oc-runtime-icon">@include('opconsole::partials.icon', ['name' => $icon])</span>
                                        <span class="oc-runtime-text"><strong>{{ $label }}</strong><small>{{ $service['value'] ?: $service['hint'] }}</small>
                                            @if ($service['status'] !== 'healthy')<small class="oc-runtime-hint">{{ $service['hint'] }}</small>@endif
                                            @if ($service['fix'])<code class="oc-runtime-fix" dir="ltr">{{ $service['fix'] }}</code>@endif
                                        </span>
                                        <span class="oc-status-pill {{ $ocToneOf($service['status']) }}"><span class="oc-dot {{ $ocToneOf($service['status']) }}" aria-hidden="true"></span>{{ $service['label'] }}</span>
                                    </a>
                                @endforeach
                                <a href="{{ $overview['maintenance']['action'] }}" class="oc-runtime-row {{ $overview['maintenance']['down'] ? 'is-warning' : 'is-ok' }}">
                                    <span class="oc-runtime-icon">@include('opconsole::partials.icon', ['name' => 'power'])</span>
                                    <span class="oc-runtime-text"><strong>Maintenance mode</strong><small>{{ $overview['maintenance']['down'] ? 'Visitors receive a 503 response.' : 'Application accepts normal traffic.' }}</small></span>
                                    <span class="oc-status-pill {{ $overview['maintenance']['down'] ? 'is-warning' : 'is-ok' }}"><span class="oc-dot {{ $overview['maintenance']['down'] ? 'is-warning' : 'is-ok' }}" aria-hidden="true"></span>{{ $overview['maintenance']['down'] ? 'Enabled' : 'Off' }}</span>
                                </a>
                            </div>
                        </section>

                        @if ($history['available'] && count($history['items']))
                            <section class="oc-card" aria-labelledby="oc-recent-title">
                                <div class="oc-card-head">
                                    <span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'clock'])</span>
                                    <div><h2 id="oc-recent-title">Recent activity</h2><small>Latest audited operations</small></div>
                                    <a href="#history" class="oc-btn oc-btn-sm oc-btn-secondary oc-ms-auto">All history</a>
                                </div>
                                <ul class="oc-feed">
                                    @foreach (array_slice($history['items'], 0, 4) as $run)
                                        <li>
                                            <span class="oc-dot {{ $run['statusClass'] }}" aria-hidden="true"></span>
                                            <span class="oc-feed-text"><strong>{{ $run['actionLabel'] }}</strong> <span class="oc-feed-actor"><bdi>{{ $run['actor'] }}</bdi></span></span>
                                            <span class="oc-sr-only">{{ $run['statusLabel'] }}</span>
                                            @if ($run['startedIso'])<time datetime="{{ $run['startedIso'] }}" title="{{ $run['startedExact'] }}">{{ $run['startedRelative'] }}</time>@endif
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ================= Operations ================= --}}
            @if ($hasOperationsTab)
            <div class="oc-tab-panel" id="oc-panel-operations" role="tabpanel" aria-labelledby="oc-tab-operations" data-oc-panel="operations">
            @endif

            {{-- Output of the last action comes first: it is why this tab opened. --}}
            @if ($output)
                <section class="oc-terminal" aria-label="Last operation output" data-oc-output role="{{ $output['ok'] ? 'status' : 'alert' }}" aria-live="{{ $output['ok'] ? 'polite' : 'assertive' }}">
                    <div class="oc-terminal-head">
                        <span class="oc-dot {{ $output['ok'] ? 'is-ok' : 'is-bad' }}" aria-hidden="true"></span>
                        <h2>Output: {{ $output['title'] }}</h2>
                        <span @class(['oc-status-pill', 'is-ok' => $output['ok'], 'is-bad' => ! $output['ok']])>{{ $output['ok'] ? 'Succeeded' : 'Failed' }}</span>
                        <button type="button" class="oc-btn oc-btn-sm oc-btn-ghost-dark oc-ms-auto" data-copy="{{ $output['text'] }}">
                            @include('opconsole::partials.icon', ['name' => 'copy'])
                            <span data-oc-copy-label>Copy</span>
                        </button>
                    </div>
                    <pre class="oc-output" dir="ltr">{{ $output['text'] }}</pre>
                    @if ($output['hint'] ?? null)
                        <div class="oc-terminal-hint">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<span>{{ $output['hint'] }}</span>@if (isset($actions['octane_reload']))<a href="#action-octane_reload" class="oc-advice-link"><span>Reload workers</span>@include('opconsole::partials.icon', ['name' => 'arrow-right'])</a>@endif</div>
                    @endif
                </section>
            @endif

            @if (count($actions))
                @php($ocActionZones = ['safe' => ['Safe · no downtime', 'Read-only or cache-level operations.', array_filter($actions, fn ($a) => $a['risk'] === 'low')], 'danger' => ['Danger zone', 'Changes the database schema or site availability.', array_filter($actions, fn ($a) => $a['risk'] !== 'low')]])
                @foreach ($ocActionZones as $ocZone => [$ocZoneTitle, $ocZoneText, $ocZoneActions])
                    @if ($ocZoneActions)
                        <section class="oc-zone oc-zone-{{ $ocZone }}" aria-label="{{ $ocZoneTitle }}">
                            <div class="oc-zone-head"><h2>{{ $ocZoneTitle }}</h2><small>{{ $ocZoneText }}</small></div>
                            <div class="oc-actions">
                                @foreach ($ocZoneActions as $key => $action)
                                    <article @class(['oc-action', 'is-wide' => $action['wide'] ?? false, 'is-high-risk' => $action['risk'] === 'high', 'is-medium-risk' => $action['risk'] === 'medium', 'is-disabled' => ! $action['enabled']]) id="action-{{ $key }}">
                                        <div class="oc-action-top">
                                            <span class="oc-action-icon">@include('opconsole::partials.icon', ['name' => $action['icon']])</span>
                                            <span @class(['oc-risk-badge', 'is-high' => $action['risk'] === 'high', 'is-medium' => $action['risk'] === 'medium'])>{{ $action['riskLabel'] }}</span>
                                        </div>
                                        <h3>{{ $action['title'] }}</h3>
                                        <code class="oc-command-pill" dir="ltr">{{ $action['command'] }}</code>
                                        <p>{{ $action['text'] }}</p>

                                        @if ($action['single'] ?? [])
                                            {{-- Every pending file in apply order: run all below, or pick one to run on its own. --}}
                                            <div class="oc-migrations-head"><span>{{ count($action['single']) }} pending · applied top to bottom</span></div>
                                            <ol class="oc-migrations" dir="ltr" aria-label="Pending migrations" data-oc-scroll-fade="y" tabindex="0">
                                                @foreach ($action['single'] as $ocMigration)
                                                    <li>
                                                        <span class="oc-migration-index" aria-hidden="true">{{ $loop->iteration }}</span>
                                                        <span class="oc-migration-name">{{ $ocMigration['name'] }}</span>
                                                        <form
                                                            method="POST"
                                                            action="{{ $action['url'] }}"
                                                            data-confirm="{{ $ocMigration['confirm'] }}"
                                                            data-confirm-title="Run one migration"
                                                            data-confirm-accept="Run this migration"
                                                            data-confirm-command="{{ $ocMigration['command'] }}"
                                                            data-confirm-risk="{{ $action['risk'] }}"
                                                            data-confirm-risk-label="{{ $action['riskLabel'] }}"
                                                            data-confirm-environment="{{ $environment['name'] }}"
                                                        >
                                                            @csrf
                                                            <input type="hidden" name="migration" value="{{ $ocMigration['name'] }}">
                                                            <button type="submit" class="oc-btn oc-btn-sm oc-btn-secondary" @disabled(! $action['enabled']) aria-label="Run only {{ $ocMigration['name'] }}">
                                                                @include('opconsole::partials.icon', ['name' => 'play'])
                                                                <span data-oc-btn-label>Run only this</span>
                                                            </button>
                                                        </form>
                                                    </li>
                                                @endforeach
                                            </ol>
                                        @elseif ($action['pending'])
                                            <ul class="oc-migrations" dir="ltr" aria-label="Pending migrations" data-oc-scroll-fade="y" tabindex="0">
                                                @foreach ($action['pending'] as $migration)
                                                    <li><span class="oc-migration-name">{{ $migration }}</span></li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        <div class="oc-action-foot">
                                            @if ($action['disabledReason'])
                                                <small class="oc-action-disabled-note" id="oc-action-disabled-{{ $key }}">@include('opconsole::partials.icon', ['name' => 'info']){{ $action['disabledReason'] }}</small>
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
                                                data-confirm-environment="{{ $environment['name'] }}"
                                            >
                                                @csrf
                                                <button type="submit" class="oc-btn {{ $action['style'] }} oc-w-full" @disabled(! $action['enabled']) @if ($action['disabledReason']) aria-describedby="oc-action-disabled-{{ $key }}" @endif>
                                                    @include('opconsole::partials.icon', ['name' => $action['icon']])
                                                    <span data-oc-btn-label>{{ $action['button'] }}</span>
                                                </button>
                                            </form>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @endforeach
            @endif

            @if ($hasOperationsTab)
            </div>
            @endif

            {{-- ================= Failed jobs ================= --}}
            @if ($failedJobs !== null)
            <div class="oc-tab-panel" id="oc-panel-jobs" role="tabpanel" aria-labelledby="oc-tab-jobs" data-oc-panel="jobs">
                <section class="oc-card" id="failed-jobs" aria-labelledby="oc-failed-jobs-title">
                    <div class="oc-card-head">
                        <span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'layers'])</span>
                        <div>
                            <h2 id="oc-failed-jobs-title">Failed jobs</h2>
                            <small>Newest first · driver <code>{{ $failedJobs['driver'] }}</code> · payloads are never shown</small>
                        </div>
                        <span @class(['oc-checks-summary', 'oc-ms-auto', 'is-bad' => $failedJobs['total'] > 0, 'is-ok' => $failedJobs['available'] && $failedJobs['total'] === 0, 'is-neutral' => ! $failedJobs['available']])>
                            @if (! $failedJobs['available'])
                                Not stored
                            @elseif ($failedJobs['total'] === 0)
                                None
                            @else
                                {{ $failedJobs['total'] }} failed
                            @endif
                        </span>
                    </div>

                    @if (! $failedJobs['available'])
                        @include('opconsole::partials.empty-state', ['message' => 'Failed jobs are not being stored (queue.failed.driver is "null"). Set QUEUE_FAILED_DRIVER=database-uuids and run the failed_jobs migration to track them.'])
                    @elseif ($failedJobs['error'])
                        <div class="oc-card-content"><p class="oc-advice oc-advice-danger" role="alert">@include('opconsole::partials.icon', ['name' => 'alert-octagon'])<span>The failed-job store could not be read: <code dir="ltr">{{ $failedJobs['error'] }}</code></span></p></div>
                    @elseif ($failedJobs['total'] === 0)
                        @include('opconsole::partials.empty-state', ['message' => 'No failed jobs. Jobs that exhaust their retries will appear here.'])
                    @else
                        <div class="oc-toolbar oc-toolbar-pad">
                            <label class="oc-search" for="oc-failed-jobs-search">
                                @include('opconsole::partials.icon', ['name' => 'search'])
                                <span class="oc-sr-only">Filter failed jobs</span>
                                <input id="oc-failed-jobs-search" class="oc-input" type="search" placeholder="Filter by job, queue or error…" data-oc-filter-input="[data-oc-failed-job]" autocomplete="off">
                            </label>
                            <span class="oc-muted-text">Showing {{ $failedJobs['shown'] }} of {{ $failedJobs['total'] }}@if (! $failedJobs['canRetry']) · read-only: your account cannot retry jobs @endif</span>
                        </div>
                        <ul class="oc-rows oc-failed-jobs">
                            @foreach ($failedJobs['items'] as $job)
                                <li class="oc-row oc-failed-job is-bad" data-oc-failed-job>
                                    <span class="oc-stripe" aria-hidden="true"></span>
                                    <div class="oc-row-main">
                                        <div class="oc-row-title">
                                            <strong dir="ltr">{{ $job['name'] }}</strong>
                                            @if ($job['queue'] !== '')<span class="oc-status-pill is-neutral">{{ $job['queue'] }}</span>@endif
                                        </div>
                                        <small>
                                            <span dir="ltr">{{ $job['connection'] }}</span> ·
                                            @if ($job['failedExact'])<time datetime="{{ $job['failedAt'] }}" title="{{ $job['failedExact'] }}">failed {{ $job['failedRelative'] }}</time>@else failed at an unknown time @endif
                                            · ID <code class="oc-inline-code" dir="ltr">{{ $job['id'] }}</code>
                                        </small>
                                        @if ($job['exception'] !== '')
                                            <code class="oc-failed-job-error" dir="ltr">{{ $job['exception'] }}</code>
                                        @endif
                                    </div>
                                    @if ($job['retryUrl'])
                                        <form
                                            method="POST"
                                            action="{{ $job['retryUrl'] }}"
                                            data-confirm="Push {{ $job['name'] }} back onto the {{ $job['queue'] ?: 'default' }} queue? It runs again with its original payload, so any side effects (emails, charges, API calls) can happen a second time."
                                            data-confirm-title="Retry failed job"
                                            data-confirm-accept="Retry job"
                                            data-confirm-command="php artisan queue:retry {{ $job['id'] }}"
                                            data-confirm-risk="medium"
                                            data-confirm-risk-label="Medium risk · Re-runs the job"
                                            data-confirm-environment="{{ $environment['name'] }}"
                                        >
                                            @csrf
                                            <button type="submit" class="oc-btn oc-btn-sm oc-btn-accent" aria-label="Retry {{ $job['name'] }} ({{ $job['id'] }})">
                                                @include('opconsole::partials.icon', ['name' => 'refresh'])
                                                <span data-oc-btn-label>Retry</span>
                                            </button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <div class="oc-empty oc-filter-empty" data-oc-filter-empty-for="[data-oc-failed-job]" hidden><p>No failed jobs match your filter.</p></div>
                    @endif
                </section>
            </div>
            @endif

            {{-- ================= Health ================= --}}
            <div class="oc-tab-panel" id="oc-panel-health" role="tabpanel" aria-labelledby="oc-tab-health" data-oc-panel="health">
                <details class="oc-card oc-collapsible" id="checks"{{ $checks['hasProblems'] ? ' open' : '' }}>
                    <summary class="oc-card-head">
                        <span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'heart-rate'])</span>
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
                        <span class="oc-chevron">@include('opconsole::partials.icon', ['name' => 'chevron-down'])</span>
                    </summary>
                    <div class="oc-card-content">
                        @if ($overview['total'])
                            @include('opconsole::partials.segbar', ['counts' => $overview, 'label' => $checks['summary'], 'class' => 'oc-segbar-lg'])
                        @endif

                        <div class="oc-toolbar">
                            <div class="oc-health-filter" role="group" aria-labelledby="oc-health-filter-label">
                                <span class="oc-filter-label" id="oc-health-filter-label">Show checks</span>
                                {{-- The select works without JavaScript-driven chips; opconsole.js swaps in the chips and keeps them in sync. --}}
                                <select id="oc-health-filter" class="oc-input" data-oc-health-filter aria-labelledby="oc-health-filter-label">
                                    <option value="all">All statuses</option>
                                    <option value="attention">Needs attention</option>
                                    <option value="critical">Critical</option>
                                    <option value="warning">Warnings</option>
                                    <option value="unknown">Unverified</option>
                                    <option value="healthy">Healthy</option>
                                </select>
                                <div class="oc-chips" data-oc-health-chips hidden>
                                    @foreach ([
                                        ['all', 'All', $overview['total'], null],
                                        ['attention', 'Needs attention', $overview['critical'] + $overview['warnings'] + $overview['advisory'] + $overview['unknown'], null],
                                        ['critical', 'Critical', $overview['critical'], 'is-bad'],
                                        ['warning', 'Warning', $overview['warnings'] + $overview['advisory'], 'is-warning'],
                                        ['unknown', 'Unverified', $overview['unknown'], 'is-unknown'],
                                        ['healthy', 'Healthy', $overview['healthy'], 'is-ok'],
                                    ] as [$ocChipValue, $ocChipLabel, $ocChipCount, $ocChipTone])
                                        <button type="button" class="oc-chip" data-oc-health-chip="{{ $ocChipValue }}" aria-pressed="false">
                                            @if ($ocChipTone)<span class="oc-dot {{ $ocChipTone }}" aria-hidden="true"></span>@endif
                                            <span>{{ $ocChipLabel }}</span> <b>{{ $ocChipCount }}</b>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            <label class="oc-search" for="oc-health-search">
                                @include('opconsole::partials.icon', ['name' => 'search'])
                                <span class="oc-sr-only">Filter checks by name</span>
                                <input id="oc-health-search" class="oc-input" type="search" placeholder="Filter checks…" data-oc-health-search autocomplete="off">
                            </label>
                            <span class="oc-sr-only" data-oc-health-count aria-live="polite"></span>
                        </div>

                        <div class="oc-check-groups">
                            @foreach ($checks['groups'] as $group)
                                <div class="oc-check-group" data-oc-check-group>
                                    <h3 class="oc-check-group-title">
                                        <span>{{ $group['name'] }}</span>
                                        <small>
                                            {{ $group['passed'] }}/{{ $group['total'] }} healthy @if ($group['unknown']) · {{ $group['unknown'] }} unknown @endif
                                            <span class="oc-minibar" aria-hidden="true">@foreach ($group['items'] as $check)<i class="{{ $check['class'] }}"></i>@endforeach</span>
                                        </small>
                                    </h3>
                                    <ul class="oc-checks">
                                        @foreach ($group['items'] as $check)
                                            <li class="oc-check {{ $check['class'] }}" data-oc-check-item data-oc-status="{{ $check['status'] }}">
                                                <details @if ($check['status'] !== 'healthy' && ! ($check['status'] === 'warning' && $check['severity'] === 'info')) open @endif>
                                                    <summary>
                                                        <span class="oc-check-icon">@include('opconsole::partials.icon', ['name' => $check['icon']])</span>
                                                        <span class="oc-check-label"><strong>{{ $check['label'] }}</strong><small>{{ $check['hint'] }}</small></span>
                                                        @if ($check['value'])<code class="oc-check-value" dir="ltr">{{ $check['value'] }}</code>@else<span></span>@endif
                                                        <span class="oc-status-pill {{ $check['class'] }}">{{ $check['badge'] }}</span>
                                                    </summary>
                                                    <div class="oc-check-more">
                                                        <p>{{ $check['hint'] }}</p>
                                                        @if ($check['value'])<p class="oc-check-value-full">Current value: <code dir="ltr">{{ $check['value'] }}</code></p>@endif
                                                        @if ($check['fix'] && (! $check['ok'] || $check['status'] === 'unknown'))
                                                            <div class="oc-fix"><span class="oc-fix-label">Fix</span><code dir="ltr">{{ $check['fix'] }}</code><button type="button" class="oc-fix-copy" data-copy="{{ $check['fix'] }}" aria-label="Copy fix for {{ $check['label'] }}">@include('opconsole::partials.icon', ['name' => 'copy'])<span data-oc-copy-label>Copy</span></button></div>
                                                        @endif
                                                    </div>
                                                </details>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                        <div class="oc-empty oc-filter-empty" data-oc-filter-empty @if ($checks['groups']) hidden @endif>
                            <p>No health checks match this filter.</p>
                            <button type="button" class="oc-btn oc-btn-secondary" data-oc-health-reset>Show all checks</button>
                        </div>
                    </div>
                </details>
            </div>

            {{-- ================= Security ================= --}}
            <div class="oc-tab-panel" id="oc-panel-security" role="tabpanel" aria-labelledby="oc-tab-security" data-oc-panel="security">
                @php($securityStatus = $securityReport['status'] ?? 'never_scanned')
                @php($securityFresh = $securityStatus === 'complete' && empty($securityReport['stale']))
                @php($securityTone = $securityFresh ? (empty($securityReport['advisories']) ? 'is-ok' : 'is-bad') : ($securityStatus === 'complete' ? 'is-warning' : ($securityStatus === 'failed' ? 'is-bad' : 'is-unknown')))
                @php($securityMaxAge = max(1, (int) config('opconsole.security.max_age_seconds', 604800)))
                @php($securityAge = ! empty($securityReport['scanned_at']) ? max(0, now()->getTimestamp() - \Illuminate\Support\Carbon::parse($securityReport['scanned_at'])->getTimestamp()) : null)
                <section class="oc-security" id="security" aria-labelledby="oc-security-title">
                    <div class="oc-security-top">
                        <div class="oc-card oc-security-summary">
                            <div class="oc-card-head">
                                <span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'shield'])</span>
                                <div><h2 id="oc-security-title">Security</h2><small>Host dependencies and runtime Laravel configuration</small></div>
                                <span class="oc-status-pill {{ $securityTone }} oc-ms-auto">
                                    @include('opconsole::partials.icon', ['name' => match ($securityTone) { 'is-ok' => 'check-circle', 'is-bad' => 'x-circle', 'is-warning' => 'alert-circle', default => 'help-circle' }])
                                    <span>{{ $securityFresh ? (empty($securityReport['advisories']) ? 'No known vulnerabilities' : count($securityReport['advisories']).' active '.(count($securityReport['advisories']) === 1 ? 'advisory' : 'advisories')) : ($securityStatus === 'complete' ? 'Stale report' : ucfirst(str_replace('_', ' ', $securityStatus))) }}</span>
                                </span>
                            </div>
                            <div class="oc-card-content">
                                @if ($securityFresh)
                                    @php($securityCounts = collect($securityReport['advisories'] ?? [])->groupBy(fn ($advisory) => $advisory['severity'] ?? 'not supplied')->map->count())
                                    <ul class="oc-severity-stats" aria-label="Vulnerability counts by severity">
                                        @foreach (['critical', 'high', 'medium', 'low', 'not supplied'] as $level)
                                            <li @class(['oc-sev', 'oc-sev-'.str_replace(' ', '-', $level), 'is-zero' => ($securityCounts[$level] ?? 0) === 0])><strong>{{ $securityCounts[$level] ?? 0 }}</strong><span>{{ ucfirst($level) }}</span></li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="oc-muted-text">Severity counts appear once a current scan completes.</p>
                                @endif
                            </div>
                        </div>
                        <div class="oc-card oc-security-status" role="status" aria-live="polite">
                            <div class="oc-card-content">
                                <strong>{{ $securityFresh ? 'Completed scan' : 'Scan status: '.str_replace('_', ' ', $securityReport['status'] ?? 'never scanned') }}</strong>
                                @if ($securityAge !== null)
                                    @php($securityAgePct = min(100, (int) round(100 * $securityAge / $securityMaxAge)))
                                    <svg class="oc-meter {{ $securityAgePct >= 100 ? 'is-warning' : 'is-ok' }}" viewBox="0 0 100 6" preserveAspectRatio="none" role="img" aria-label="Scan age {{ $securityAgePct }}% of the freshness window"><rect class="oc-meter-track" width="100" height="6" rx="3" /><rect class="oc-meter-fill" width="{{ max(2, $securityAgePct) }}" height="6" rx="3" /></svg>
                                    <div class="oc-meter-legend">
                                        <span>Scanned {{ \Illuminate\Support\Carbon::parse($securityReport['scanned_at'])->locale('en')->diffForHumans() }}</span>
                                        <span>{{ $securityAgePct >= 100 ? 'Past the freshness window' : 'Stale '.now()->addSeconds($securityMaxAge - $securityAge)->locale('en')->diffForHumans(['parts' => 1]) }}</span>
                                    </div>
                                @endif
                                <dl class="oc-security-facts">
                                    <div><dt>Scope</dt><dd>{{ $securityReport['scope'] ?? 'composer.lock · production dependencies' }}</dd></div>
                                    @if (!empty($securityReport['scanned_at']))<div><dt>Scanned</dt><dd><time datetime="{{ $securityReport['scanned_at'] }}">{{ $securityReport['scanned_at'] }}</time></dd></div>@endif
                                </dl>
                                <div class="oc-fix"><code dir="ltr">php artisan opconsole:security</code><button type="button" class="oc-fix-copy" data-copy="php artisan opconsole:security" aria-label="Copy scan command">@include('opconsole::partials.icon', ['name' => 'copy'])<span data-oc-copy-label>Copy</span></button></div>
                            </div>
                        </div>
                    </div>

                    @if (!empty($securityReport['manifest_changed']))<p class="oc-advice oc-advice-warning" role="alert">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<span>Dependency files changed since the last successful scan. Results are stale; run <code>php artisan opconsole:security</code>.</span></p>@endif
                    @if (!empty($securityReport['stale']) && empty($securityReport['manifest_changed']))<p class="oc-advice oc-advice-warning" role="alert">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<span>The last successful scan is older than the configured freshness window. Run <code>php artisan opconsole:security</code>.</span></p>@endif
                    @if (!empty($securityReport['last_failure']))<p class="oc-advice oc-advice-danger" role="alert">@include('opconsole::partials.icon', ['name' => 'alert-octagon'])<span>Latest scan failed @if(!empty($securityReport['last_failure']['at']))<time datetime="{{ $securityReport['last_failure']['at'] }}">{{ $securityReport['last_failure']['at'] }}</time>@endif: {{ $securityReport['last_failure']['message'] }}. Last successful report: {{ $securityReport['last_success_at'] ?? 'none' }}.</span></p>@endif
                    @if (!empty($securityReport['version_mismatches']))<div class="oc-advice oc-advice-warning" role="status">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<div><strong>Installed and locked dependency versions differ:</strong><ul>@foreach($securityReport['version_mismatches'] as $mismatch)<li><code>{{ $mismatch['package'] }}</code> · lock {{ $mismatch['locked_version'] }}, installed {{ $mismatch['installed_version'] }}</li>@endforeach</ul></div></div>@endif
                    @if ($securityFresh && empty($securityReport['advisories']))<p class="oc-advice oc-advice-ok" role="status">@include('opconsole::partials.icon', ['name' => 'check-circle'])<span>No known vulnerabilities found in scanned dependencies.</span></p>@elseif (!$securityFresh)<p class="oc-advice oc-advice-warning" role="status">@include('opconsole::partials.icon', ['name' => 'help-circle'])<span>A clean result cannot be confirmed until a current scan completes.</span></p>@endif

                    @if (!empty($securityReport['advisories']))
                        <section class="oc-card" aria-labelledby="oc-advisories-title">
                            <div class="oc-card-head oc-card-head-tools">
                                <h3 id="oc-advisories-title">Advisories</h3>
                                <div class="oc-toolbar-tight">
                                    <label for="oc-security-search" class="oc-search">@include('opconsole::partials.icon', ['name' => 'search'])<span class="oc-sr-only">Search advisories</span><input id="oc-security-search" class="oc-input" type="search" placeholder="Package or CVE…" data-security-search aria-controls="oc-security-advisories" autocomplete="off"></label>
                                    <label for="oc-security-severity" class="oc-sr-only">Filter severity</label>
                                    <select id="oc-security-severity" class="oc-input" data-security-severity aria-controls="oc-security-advisories"><option value="all">All severities</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option><option value="not supplied">Not supplied</option></select>
                                </div>
                            </div>
                            <ul class="oc-rows" id="oc-security-advisories">
                                @foreach ($securityReport['advisories'] as $advisory)
                                    @php($ocSev = $advisory['severity'] ?? 'not supplied')
                                    <li class="oc-row oc-advisory oc-sev-{{ str_replace(' ', '-', $ocSev) }}" data-security-row data-security-level="{{ $ocSev }}">
                                        <span class="oc-stripe" aria-hidden="true"></span>
                                        <div class="oc-row-main">
                                            <div class="oc-row-title">
                                                <strong>@if($advisory['link'])<a href="{{ $advisory['link'] }}" rel="noopener noreferrer" target="_blank" class="oc-inline-link">{{ $advisory['title'] }}</a>@else{{ $advisory['title'] }}@endif</strong>
                                                <span class="oc-sev-badge oc-sev-{{ str_replace(' ', '-', $ocSev) }}">{{ $advisory['severity'] ?? 'Not supplied' }}</span>
                                            </div>
                                            <small class="oc-advisory-meta">
                                                <code class="oc-pkg">{{ $advisory['package'] }}</code> <span class="oc-mono-sub">{{ $advisory['version'] ?? 'version unavailable' }}</span>
                                                @if($advisory['version_mismatch'] ?? false)<strong class="oc-mismatch"> · installed version differs</strong>@endif
                                                @if($advisory['identifiers'] ?? []) · <span class="oc-mono-sub">{{ implode(', ', $advisory['identifiers']) }}</span>@endif
                                            </small>
                                            <dl class="oc-advisory-facts">
                                                <div><dt>Affected</dt><dd class="oc-mono-sub">{{ $advisory['affected'] ?? 'Not supplied' }}</dd></div>
                                                <div><dt>Published</dt><dd>{{ $advisory['published'] ?? 'Not supplied' }}</dd></div>
                                            </dl>
                                            <p class="oc-advisory-fix">{{ $advisory['remediation'] ?? 'No remediation guidance supplied; review the advisory and update through your normal dependency review process.' }}</p>
                                            <div class="oc-fix"><code dir="ltr">composer update {{ $advisory['package'] }}</code><button type="button" class="oc-fix-copy" data-copy="composer update {{ $advisory['package'] }}" aria-label="Copy update command for {{ $advisory['package'] }}">@include('opconsole::partials.icon', ['name' => 'copy'])<span data-oc-copy-label>Copy</span></button></div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if (!empty($securityReport['config_warnings']))
                        <section class="oc-card" aria-labelledby="oc-config-warnings-title">
                            <div class="oc-card-head"><span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])</span><div><h3 id="oc-config-warnings-title">Laravel configuration warnings</h3><small>{{ count($securityReport['config_warnings']) }} {{ count($securityReport['config_warnings']) === 1 ? 'warning' : 'warnings' }}</small></div></div>
                            <ul class="oc-rows">
                                @foreach($securityReport['config_warnings'] as $warning)
                                    <li class="oc-row is-warning"><span class="oc-stripe" aria-hidden="true"></span><div class="oc-row-main"><strong>{{ $warning['message'] }}</strong><small>{{ $warning['fix'] }}</small></div></li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if (!empty($securityReport['abandoned']))
                        <section class="oc-card" aria-labelledby="oc-abandoned-title">
                            <div class="oc-card-head"><span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'layers'])</span><div><h3 id="oc-abandoned-title">Abandoned packages (not confirmed vulnerabilities)</h3></div></div>
                            <ul class="oc-plain-list oc-card-content">@foreach($securityReport['abandoned'] as $item)<li><code>{{ $item['package'] }}</code>@if($item['replacement']) · suggested replacement: <code>{{ $item['replacement'] }}</code>@endif</li>@endforeach</ul>
                        </section>
                    @endif

                    @if (!empty($securityReport['ignored']))<details class="oc-disclosure"><summary>{{ count($securityReport['ignored']) }} ignored advisory records excluded from active findings</summary><ul>@foreach($securityReport['ignored'] as $item)<li><code>{{ $item['package'] }}</code>: {{ $item['title'] }} — {{ $item['ignored_reason'] ?? 'Composer reports this advisory as ignored; reason not supplied.' }}</li>@endforeach</ul></details>@endif

                    <footer class="oc-security-foot">
                        <p>Advisory data source: {{ $securityReport['source'] ?? 'Composer repositories (Packagist by default)' }}. Composer sends package names and locked versions to configured advisory repositories; credentials are not included in this report.</p>
                        <p>Run scans with <code>php artisan opconsole:security</code>; include development dependencies with <code>--include-dev</code>. Review recommended updates in your normal development and deployment process.</p>
                    </footer>
                </section>
            </div>

            {{-- ================= History ================= --}}
            <div class="oc-tab-panel" id="oc-panel-history" role="tabpanel" aria-labelledby="oc-tab-history" data-oc-panel="history">
                <details class="oc-card oc-collapsible" id="history"{{ count($history['items']) > 0 ? ' open' : '' }}>
                    <summary class="oc-card-head">
                        <span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'clock'])</span>
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
                        <span class="oc-chevron">@include('opconsole::partials.icon', ['name' => 'chevron-down'])</span>
                    </summary>
                    <div class="oc-card-content oc-flush">
                        @if (! $history['available'])
                            @include('opconsole::partials.empty-state', ['message' => 'The opconsole_runs audit table has not been migrated yet. Run migrations to persist operation history.'])
                        @elseif (! count($history['items']))
                            @include('opconsole::partials.empty-state', ['message' => 'No operations have been recorded yet.'])
                        @else
                            <div class="oc-toolbar oc-toolbar-pad">
                                <div class="oc-chips" role="group" aria-label="Filter history by status" data-oc-history-chips>
                                    <button type="button" class="oc-chip" data-oc-history-chip="all" aria-pressed="true"><span>All</span> <b>{{ count($history['items']) }}</b></button>
                                    <button type="button" class="oc-chip" data-oc-history-chip="succeeded" aria-pressed="false"><span class="oc-dot is-ok" aria-hidden="true"></span><span>Succeeded</span> <b>{{ $history['counts']['succeeded'] }}</b></button>
                                    <button type="button" class="oc-chip" data-oc-history-chip="failed" aria-pressed="false"><span class="oc-dot is-bad" aria-hidden="true"></span><span>Failed</span> <b>{{ $history['counts']['failed'] }}</b></button>
                                    @if ($history['counts']['other'])<button type="button" class="oc-chip" data-oc-history-chip="other" aria-pressed="false"><span class="oc-dot is-unknown" aria-hidden="true"></span><span>Other</span> <b>{{ $history['counts']['other'] }}</b></button>@endif
                                </div>
                            </div>
                            <div class="oc-timeline">
                                @foreach ($history['days'] as $day)
                                    <div class="oc-day" data-oc-history-day>
                                        <h3 class="oc-day-head"><span>{{ $day['label'] }}</span><small>{{ count($day['runs']) }} {{ count($day['runs']) === 1 ? 'run' : 'runs' }}</small></h3>
                                        <ol class="oc-runs">
                                            @foreach ($day['runs'] as $run)
                                                <li class="oc-run {{ $run['statusClass'] }}" data-oc-history-item data-oc-history-status="{{ in_array($run['status'], ['succeeded', 'failed'], true) ? $run['status'] : 'other' }}">
                                                    <span class="oc-run-icon" aria-hidden="true">@include('opconsole::partials.icon', ['name' => match ($run['status']) { 'succeeded' => 'check', 'failed' => 'x', 'started' => 'clock', default => 'help-circle' }])</span>
                                                    <div class="oc-run-main">
                                                        <div class="oc-row-title">
                                                            <strong>{{ $run['actionLabel'] }}</strong>
                                                            <span class="oc-status-pill {{ $run['statusClass'] }}" data-run-status="{{ $run['status'] }}">{{ $run['statusLabel'] }}</span>
                                                        </div>
                                                        <small><code class="oc-inline-code" dir="ltr">{{ $run['command'] }}</code> · <bdi>{{ $run['actor'] }}</bdi></small>
                                                        @if ($run['details'] !== '—')
                                                            <p class="oc-run-details"><bdi>{{ $run['details'] }}</bdi></p>
                                                        @endif
                                                    </div>
                                                    <div class="oc-run-side">
                                                        @if ($run['startedIso'])
                                                            <time datetime="{{ $run['startedIso'] }}" title="{{ $run['startedExact'] }}">{{ $run['startedTime'] ?? $run['startedRelative'] }}</time>
                                                            <span class="oc-sr-only">({{ $run['startedRelative'] }})</span>
                                                        @else
                                                            <span>—</span>
                                                        @endif
                                                        <span class="oc-duration" dir="ltr">
                                                            <svg class="oc-duration-bar" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="oc-meter-track" width="100" height="6" rx="3" />@if ($run['durationShare'])<rect class="oc-meter-fill" width="{{ $run['durationShare'] }}" height="6" rx="3" />@endif</svg>
                                                            <span>{{ $run['duration'] }}</span>
                                                        </span>
                                                    </div>
                                                </li>
                                            @endforeach
                                        </ol>
                                    </div>
                                @endforeach
                            </div>
                            <div class="oc-empty oc-filter-empty" data-oc-history-empty hidden><p>No operations match this filter.</p></div>
                        @endif
                    </div>
                </details>
            </div>

            {{-- ================= System ================= --}}
            <div class="oc-tab-panel" id="oc-panel-system" role="tabpanel" aria-labelledby="oc-tab-system" data-oc-panel="system">
                <details class="oc-system-info" id="system-info" open>
                    <summary class="oc-system-toggle">
                        <span class="oc-inline-flex">@include('opconsole::partials.icon', ['name' => 'info']) <span>System information</span></span>
                        <span class="oc-inline-flex"><span>View details</span> <span class="oc-chevron">@include('opconsole::partials.icon', ['name' => 'chevron-down'])</span></span>
                    </summary>
                    <div class="oc-toolbar">
                        <label class="oc-search" for="oc-system-search">
                            @include('opconsole::partials.icon', ['name' => 'search'])
                            <span class="oc-sr-only">Find a setting</span>
                            <input id="oc-system-search" class="oc-input" type="search" placeholder="Find a setting…" data-oc-system-search autocomplete="off">
                        </label>
                        <span class="oc-muted-text">Runtime and service configuration summary (contains no raw secrets)</span>
                        <button type="button" class="oc-btn oc-btn-accent oc-ms-auto" data-copy="{{ $infoText }}" title="Copy system summary">
                            @include('opconsole::partials.icon', ['name' => 'copy'])
                            <span data-oc-copy-label>Copy summary for support</span>
                        </button>
                    </div>
                    @php($ocProd = $environment['production'])
                    @php($ocFlagFor = fn (string $group, string $label, string $value) => match (true) { $group === 'Cache' && in_array($label, ['Configuration', 'Routes'], true) && $value === 'No' && $ocProd => 'Cache in production', $group === 'PHP' && $label === 'display_errors' && $value === 'On' && $ocProd => 'Turn off in production', $group === 'Performance' && $label === 'OPcache' && ! str_starts_with($value, 'Enabled') && $ocProd => 'Enable in production', $group === 'Performance' && $label === 'Xdebug' && $value === 'Loaded' && $ocProd => 'Slows production', $group === 'Database' && $label === 'Pending migrations' && $value !== '0' => 'Run migrations', default => null })
                    <div class="oc-info-grid">
                        @foreach ($info as $group => $rows)
                            <section class="oc-card" data-oc-system-group>
                                <div class="oc-card-head"><h3 class="oc-info-group">{{ $group }}</h3></div>
                                <dl class="oc-info">
                                    @if ($group === 'Application')
                                        <div data-oc-system-row><dt>Debug mode</dt><dd><bdi>{{ $environment['debug'] ? 'On' : 'Off' }}</bdi>@if ($environment['debug'] && $environment['production'])<span class="oc-flag">Turn off in production</span>@endif</dd><button type="button" class="oc-copy-mini" data-copy="{{ $environment['debug'] ? 'On' : 'Off' }}" aria-label="Copy Debug mode">@include('opconsole::partials.icon', ['name' => 'copy'])</button></div>
                                    @endif
                                    @foreach ($rows as $label => $value)
                                        <div data-oc-system-row>
                                            <dt>{{ $label }}</dt>
                                            @if ($group === 'PHP extensions' && ! in_array($value, ['none', 'none of the common ones'], true))
                                                <dd><span class="oc-ext-chips">@foreach (explode(', ', (string) $value) as $ocExt)<span @class(['oc-ext-chip', 'is-missing' => $label === 'Not loaded'])>{{ $ocExt }}</span>@endforeach</span></dd>
                                            @else
                                                <dd><bdi>{{ $value }}</bdi>@if ($ocFlag = $ocFlagFor((string) $group, (string) $label, (string) $value))<span class="oc-flag">{{ $ocFlag }}</span>@endif</dd>
                                            @endif
                                            <button type="button" class="oc-copy-mini" data-copy="{{ $value }}" aria-label="Copy {{ $label }}">@include('opconsole::partials.icon', ['name' => 'copy'])</button>
                                        </div>
                                    @endforeach
                                </dl>
                            </section>
                        @endforeach
                    </div>
                    @if ($dbError)
                        <small class="oc-db-error" dir="ltr">{{ $dbError }}</small>
                    @endif
                    <div class="oc-empty oc-filter-empty" data-oc-system-empty hidden><p>No settings match your search.</p></div>
                </details>
            </div>

            {{-- ================= Logs ================= --}}
            <div class="oc-tab-panel" id="oc-panel-logs" role="tabpanel" aria-labelledby="oc-tab-logs" data-oc-panel="logs">
                <section class="oc-logs" id="logs" aria-labelledby="oc-logs-heading">
                    <h2 id="oc-logs-heading" class="oc-sr-only">System logs</h2>
                    @if (! $log)
                        <div class="oc-card">
                            @include('opconsole::partials.empty-state', ['message' => 'No log files yet.'])
                        </div>
                    @else
                        <div class="oc-logs-layout">
                            {{-- File picker --}}
                            <nav class="oc-card oc-log-files" aria-label="Log files">
                                <div class="oc-card-head"><span class="oc-card-icon">@include('opconsole::partials.icon', ['name' => 'file-text'])</span><div><h3>Files</h3><small>{{ count($logFiles) }} {{ count($logFiles) === 1 ? 'file' : 'files' }}</small></div></div>
                                <ul class="oc-file-list" data-oc-scroll-fade="y">
                                    @foreach ($logFiles as $file)
                                        <li @class(['oc-log-file', 'is-active' => $file['active']])>
                                            <a href="{{ $file['url'] }}" class="oc-file-link" aria-label="Open {{ $file['name'] }}" @if ($file['active']) aria-current="true" @endif data-oc-palette-item="Log file · {{ $file['name'] }}">
                                                @include('opconsole::partials.icon', ['name' => 'file'])
                                                <span class="oc-file-text"><span class="oc-file-name" dir="ltr">{{ $file['name'] }}</span><small>{{ $file['meta'] }}</small></span>
                                            </a>
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
                                        </li>
                                    @endforeach
                                </ul>
                            </nav>

                            <div class="oc-log-main">
                                @if ($log['timeline'])
                                    <div class="oc-card oc-card-content oc-histogram-card">
                                        <div class="oc-histogram-head"><strong>Entries per hour</strong><small>24 hours up to the newest entry · <span class="oc-legend is-bad">errors</span> <span class="oc-legend is-warning">warnings</span> <span class="oc-legend is-other">other</span></small></div>
                                        <svg class="oc-histogram" viewBox="0 0 {{ count($log['timeline']) * 10 }} 64" preserveAspectRatio="none" role="img" aria-label="Log entries per hour for the last 24 hours of this file" focusable="false">
                                            @foreach ($log['timeline'] as $i => $hour)
                                                @php($ocY = 64)
                                                <g>
                                                    <title>{{ $hour['label'] }} · {{ $hour['errors'] }} errors, {{ $hour['warnings'] }} warnings, {{ $hour['other'] }} other</title>
                                                    <rect class="oc-bar-hit" x="{{ $i * 10 }}" y="0" width="10" height="64" />
                                                    @foreach ([['is-other', $hour['otherPct']], ['is-warning', $hour['warningsPct']], ['is-bad', $hour['errorsPct']]] as [$ocBarTone, $ocBarPct])
                                                        @if ($ocBarPct > 0)
                                                            @php($ocH = max(1.5, $ocBarPct * 0.64))
                                                            @php($ocY -= $ocH)
                                                            <rect class="{{ $ocBarTone }}" x="{{ $i * 10 + 1.5 }}" y="{{ round($ocY, 2) }}" width="7" height="{{ round($ocH, 2) }}" rx="1" />
                                                        @endif
                                                    @endforeach
                                                </g>
                                            @endforeach
                                        </svg>
                                        <div class="oc-histogram-axis" dir="ltr">
                                            @foreach ($log['timeline'] as $i => $hour)
                                                @if ($i % 6 === 0 || $loop->last)<span>{{ $hour['label'] }}</span>@endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if ($log['envChips'])
                                    <div class="oc-chips" role="group" aria-label="Filter by environment">
                                        @foreach ($log['envChips'] as $chip)
                                            <a href="{{ $chip['url'] }}" @class(['oc-chip', 'is-active' => $chip['active']]) @if ($chip['active']) aria-current="true" @endif>
                                                <span>{{ $chip['label'] }}</span> <b>{{ $chip['count'] }}</b>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif

                                <div class="oc-toolbar">
                                    <div class="oc-chips" role="group" aria-label="Filter by log level">
                                        @foreach ($log['levelChips'] as $chip)
                                            <a href="{{ $chip['url'] }}" @class(['oc-chip', $chip['style'], 'is-active' => $chip['active']]) @if ($chip['active']) aria-current="true" @endif>
                                                @if ($chip['style'])<span class="oc-dot {{ $chip['style'] }}" aria-hidden="true"></span>@endif
                                                <span>{{ $chip['label'] }}</span> <b>{{ $chip['count'] }}</b>
                                            </a>
                                        @endforeach
                                    </div>
                                    <div class="oc-toolbar-tight">
                                        <form method="GET" action="{{ $indexUrl }}#logs" class="oc-log-search" role="search">
                                            <input type="hidden" name="log" value="{{ $log['name'] }}">
                                            <input type="hidden" name="env" value="{{ $log['env'] }}">
                                            @if ($log['level'])<input type="hidden" name="level" value="{{ $log['level'] }}">@endif
                                            <label for="oc-log-search-input" class="oc-search">
                                                @include('opconsole::partials.icon', ['name' => 'search'])
                                                <span class="oc-sr-only">Search logs</span>
                                                <input id="oc-log-search-input" type="search" aria-label="Search logs" name="q" value="{{ $log['search'] }}" class="oc-input" placeholder="Search messages and stack traces…" maxlength="200" dir="auto">
                                            </label>
                                            <button type="submit" class="oc-btn oc-btn-accent" aria-label="Search" title="Search">
                                                @include('opconsole::partials.icon', ['name' => 'search'])
                                            </button>
                                            @if ($log['search'] !== '')
                                                <a href="{{ $log['clearSearchUrl'] }}" class="oc-btn oc-btn-secondary" aria-label="Clear search" title="Clear search">
                                                    @include('opconsole::partials.icon', ['name' => 'x'])
                                                </a>
                                            @endif
                                        </form>
                                        @if ($log['downloadUrl'])
                                            <a href="{{ $log['downloadUrl'] }}" class="oc-btn oc-btn-secondary">
                                                @include('opconsole::partials.icon', ['name' => 'download'])
                                                <span>Download file</span>
                                            </a>
                                        @endif
                                    </div>
                                </div>

                                <p class="oc-log-summary">
                                    <span>{{ $log['summary'] }}</span>
                                    @if ($log['truncated'])
                                        <span>· Showing the latest 2 MB tail only. Download the file to inspect older entries.</span>
                                    @endif
                                    @if ($log['resetFiltersUrl'])
                                        · <a href="{{ $log['resetFiltersUrl'] }}" class="oc-inline-link">Reset filters</a>
                                    @endif
                                    <span class="oc-kbd-hint" data-oc-js-only hidden>· <kbd class="oc-kbd">j</kbd> <kbd class="oc-kbd">k</kbd> move between entries</span>
                                </p>

                                {{-- Entries (messageHtml / detailsHtml are pre-escaped by PagePresenter, with <mark> around search matches) --}}
                                @forelse ($log['entries'] as $entry)
                                    @if ($loop->first)<div class="oc-card oc-log-list" data-oc-log-list>@endif
                                    <details class="oc-log-entry {{ $entry['style'] }}">
                                        <summary>
                                            <span class="oc-stripe" aria-hidden="true"></span>
                                            <span class="oc-log-level">{{ $entry['level'] }}</span>
                                            <span class="oc-log-message" dir="ltr">{!! $entry['messageHtml'] !!}</span>
                                            <span class="oc-log-meta" dir="ltr">
                                                @if ($entry['repeat'] > 1)<span class="oc-repeat" title="Seen {{ $entry['repeat'] }} times in this view">×{{ $entry['repeat'] }}</span>@endif
                                                <span>{{ $entry['meta'] }}</span>
                                            </span>
                                        </summary>
                                        <div class="oc-log-body">
                                            <div class="oc-log-body-head">
                                                <span>{{ $entry['env'] }} · {{ $entry['time'] }}@if ($entry['detailsHtml']) · application frames highlighted @endif</span>
                                                <button type="button" class="oc-btn oc-btn-sm oc-btn-ghost-dark" data-copy="{{ $entry['copy'] }}" title="Copy log entry">
                                                    @include('opconsole::partials.icon', ['name' => 'copy'])
                                                    <span data-oc-copy-label>Copy entry</span>
                                                </button>
                                            </div>
                                            @if ($entry['detailsHtml'])
                                                <pre class="oc-output" dir="ltr">{!! $entry['detailsHtml'] !!}</pre>
                                            @endif
                                        </div>
                                    </details>
                                    @if ($loop->last)</div>@endif
                                @empty
                                    <div class="oc-card">
                                        @include('opconsole::partials.empty-state', [
                                            'message' => $log['emptyMessage'],
                                            'actionUrl' => $log['resetFiltersUrl'],
                                            'actionLabel' => $log['resetFiltersUrl'] ? 'Reset filters' : null,
                                        ])
                                    </div>
                                @endforelse

                                @if ($log['groups'])
                                    <details class="oc-card oc-log-groups">
                                        <summary>@include('opconsole::partials.icon', ['name' => 'layers']) <span>Most frequent repeated entries</span></summary>
                                        @foreach ($log['groups'] as $group)
                                            <div class="oc-log-group {{ $group['style'] }}">
                                                <span class="oc-repeat">×{{ $group['count'] }}</span>
                                                <span class="oc-log-message" dir="ltr">{{ $group['message'] }}</span>
                                                <span class="oc-log-meta" dir="ltr">{{ $group['last'] }}</span>
                                            </div>
                                        @endforeach
                                    </details>
                                @endif

                                @if ($log['moreUrl'])
                                    <a href="{{ $log['moreUrl'] }}" class="oc-btn oc-btn-accent oc-w-full">
                                        @include('opconsole::partials.icon', ['name' => 'chevron-down'])
                                        <span>Show more entries</span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endif
                </section>
            </div>
        </div>

        <footer class="oc-footer">
            @include('opconsole::partials.logo', ['variant' => 'mark', 'label' => null])
            <span>Powered by <strong>OpConsole</strong></span>
        </footer>

        {{-- Command palette (JavaScript only). Entries are built from the page: tabs, checks, actions, log files. --}}
        <dialog class="oc-dialog oc-palette" id="opconsole-palette" aria-label="Search or jump to">
            <div class="oc-palette-card">
                <label class="oc-palette-search" for="opconsole-palette-input">
                    @include('opconsole::partials.icon', ['name' => 'search'])
                    <span class="oc-sr-only">Search or jump to</span>
                    <input id="opconsole-palette-input" type="search" placeholder="Jump to a tab, check, operation or log file…" autocomplete="off" role="combobox" aria-expanded="true" aria-controls="opconsole-palette-list" aria-autocomplete="list">
                    <kbd class="oc-kbd">Esc</kbd>
                </label>
                <ul class="oc-palette-list" id="opconsole-palette-list" role="listbox" aria-label="Results"></ul>
            </div>
        </dialog>

        @if ($layout)
            @include('opconsole::partials.confirm-modal')
        @endif
    </div>
@endsection
