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
    <div class="oc-embedded-shell" data-oc-theme="{{ $theme }}">
        @if ($layout)
            @include('opconsole::partials.flash')
        @endif

        <div class="oc-sr-only" id="opconsole-live-region" aria-live="polite" aria-atomic="true"></div>

        @php($ocHealthIcon = match ($overview['tone']) { 'is-bad' => 'x-circle', 'is-warning' => 'alert-circle', 'is-unknown' => 'help-circle', default => 'check-circle' })
        <header @class(['oc-header', 'is-production' => $environment['production']])>
            <div class="oc-header-main">
                @if ($backUrl)
                    <a href="{{ $backUrl }}" class="oc-back-link">
                        @include('opconsole::partials.icon', ['name' => 'arrow-left'])
                        <span>{{ $backLabel }}</span>
                    </a>
                @endif
                <div class="oc-title-row">
                    <span class="oc-brand-mark" aria-hidden="true">@include('opconsole::partials.icon', ['name' => 'terminal'])</span>
                    <h1 class="oc-title">{{ $overview['appName'] }}</h1>
                    <span @class(['oc-env-badge', 'is-prod' => $environment['production'], 'is-debug' => $environment['debug'] && $environment['production']])>
                        <span class="oc-sr-only">Environment: </span>{{ $environment['name'] }}@if ($environment['debug']) · debug @endif
                    </span>
                </div>
                <p class="oc-subtitle">Operations console <span aria-hidden="true">·</span> monitor, diagnose, maintain</p>
                <ul class="oc-header-meta" aria-label="Application summary">
                    <li class="oc-health-pill {{ $overview['tone'] }}">
                        @include('opconsole::partials.icon', ['name' => $ocHealthIcon])
                        <span>{{ $overview['state'] }}</span>
                    </li>
                    @if (! empty($info['Application']['Laravel']))<li><span class="oc-meta-key">Laravel</span> <bdi>{{ $info['Application']['Laravel'] }}</bdi></li>@endif
                    @if (! empty($info['PHP']['Version']))<li><span class="oc-meta-key">PHP</span> <bdi>{{ $info['PHP']['Version'] }}</bdi></li>@endif
                    @if ($overview['lastChecked'])<li><time datetime="{{ $overview['lastChecked']['iso'] }}">{{ $overview['lastChecked']['text'] }}</time></li>@endif
                </ul>
            </div>
            <div class="oc-header-actions">
                @if ($themeToggle)
                    <button type="button" class="oc-btn oc-btn-soft-secondary oc-theme-toggle" data-oc-theme-toggle hidden aria-label="Switch theme" title="Switch theme">
                        <span class="oc-theme-icon-dark">@include('opconsole::partials.icon', ['name' => 'moon'])</span>
                        <span class="oc-theme-icon-light">@include('opconsole::partials.icon', ['name' => 'sun'])</span>
                    </button>
                @endif
                <a href="{{ $refreshUrl }}" class="oc-btn oc-btn-soft-primary" data-oc-refresh>
                    @include('opconsole::partials.icon', ['name' => 'refresh'])
                    <span>Refresh status</span>
                </a>
            </div>
        </header>

        <div class="oc-workspace">
            {{-- Status at a glance --}}
            <section aria-label="Operational summary" class="oc-grid-tiles">
                @foreach ($tiles as $tile)
                    <div>
                        @if ($tile['link'])<a href="{{ $tile['link'] }}" class="oc-tile-link">@endif
                            <div class="oc-tile {{ $tile['tone'] }}">
                                <span class="oc-tile-label">
                                    <span class="oc-tile-icon">@include('opconsole::partials.icon', ['name' => $tile['icon']])</span>
                                    <span>{{ $tile['label'] }}</span>
                                </span>
                                <strong>{{ $tile['value'] }}</strong>
                                <span class="oc-tile-sub"><bdi>{{ $tile['sub'] }}</bdi></span>
                            </div>
                        @if ($tile['link'])</a>@endif
                    </div>
                @endforeach
            </section>

            {{-- Section tabs: progressive enhancement. Without JavaScript every panel stays visible in page order. --}}
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

            <div class="oc-tab-panel" id="oc-panel-overview" role="tabpanel" aria-labelledby="oc-tab-overview" data-oc-panel="overview">
                <section class="oc-overview-card oc-overview-hero {{ $overview['tone'] }}" id="overview" aria-labelledby="oc-overview-title">
                    <div class="oc-overview-main">
                        <span class="oc-overview-eyebrow">Application overview</span>
                        <h2 id="oc-overview-title">
                            @include('opconsole::partials.icon', ['name' => $ocHealthIcon])
                            <span>{{ $overview['state'] }}</span>
                        </h2>
                        <p>{{ $overview['summary'] }}</p>
                    </div>
                    <div class="oc-overview-meta">
                        <span class="oc-env-badge {{ $environment['production'] ? 'is-prod' : '' }}">{{ $environment['name'] }} environment</span>
                        @if ($overview['lastChecked'])
                            <time datetime="{{ $overview['lastChecked']['iso'] }}">{{ $overview['lastChecked']['text'] }}</time>
                        @else
                            <span>Check time unavailable</span>
                        @endif
                        <a href="{{ $refreshUrl }}" class="oc-inline-link" data-oc-refresh>Refresh overview</a>
                    </div>
                </section>

                <section class="oc-overview-counts" aria-label="Health check totals">
                    <a href="#checks" class="oc-count-item is-bad"><strong>{{ $overview['critical'] }}</strong><span>Critical</span></a>
                    <a href="#checks" class="oc-count-item is-warning"><strong>{{ $overview['warnings'] }}</strong><span>Warnings</span></a>
                    <a href="#checks" class="oc-count-item is-unknown"><strong>{{ $overview['unknown'] }}</strong><span>Unverified</span></a>
                    <a href="#checks" class="oc-count-item"><strong>{{ $overview['total'] }}</strong><span>Total checks</span></a>
                </section>

                @if ($advice)
                    <section class="oc-overview-notes" aria-label="Operational recommendations">
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

                <div class="oc-overview-grid">
                    <section class="oc-section oc-attention" aria-labelledby="oc-attention-title">
                        <div class="oc-section-head">
                            <b>@include('opconsole::partials.icon', ['name' => $overview['critical'] ? 'alert-octagon' : 'activity'])</b>
                            <div><h2 id="oc-attention-title">Attention needed</h2><small>Verified issues first, then checks that need verification</small></div>
                        </div>
                        <div class="oc-section-body">
                            @if ($overview['attention'])
                                <ul class="oc-attention-list">
                                    @foreach ($overview['attention'] as $item)
                                        <li class="{{ $item['tone'] }}">
                                            <span class="oc-status-pill {{ $item['tone'] }}">{{ $item['status'] }}</span>
                                            <div><strong>{{ $item['label'] }}</strong><small>{{ $item['group'] }} · {{ $item['hint'] }}</small>
                                                @if ($item['fix'])<code dir="ltr">{{ $item['fix'] }}</code>@endif
                                            </div>
                                            <a href="{{ $item['url'] }}" aria-label="Review {{ $item['label'] }}">Review</a>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($overview['moreAttention'])<a class="oc-inline-link" href="#checks">View {{ $overview['moreAttention'] }} more verified issue(s)</a>@endif
                            @else
                                <div class="oc-inline-empty">
                                    @include('opconsole::partials.icon', ['name' => $overview['unknown'] ? 'help-circle' : 'check-circle'])
                                    <p>{{ $overview['unknown'] ? 'No verified failures. Some checks are unverified; review them before relying on a fully healthy result.' : ($overview['total'] ? 'No verified issues need attention.' : 'Health information is unavailable right now.') }}</p>
                                </div>
                            @endif
                            @if ($overview['unknownItems'])
                                <details class="oc-unverified">
                                    <summary>{{ $overview['unknown'] }} unverified {{ $overview['unknown'] === 1 ? 'check' : 'checks' }}</summary>
                                    <ul>@foreach ($overview['unknownItems'] as $item)<li><strong>{{ $item['label'] }}</strong><small>{{ $item['group'] }} · {{ $item['hint'] }}</small></li>@endforeach</ul>
                                    <a class="oc-inline-link" href="#checks">Review health checks</a>
                                </details>
                            @endif
                        </div>
                    </section>

                    <section class="oc-section" aria-labelledby="oc-runtime-title">
                        <div class="oc-section-head"><b>@include('opconsole::partials.icon', ['name' => 'activity'])</b><div><h2 id="oc-runtime-title">Runtime status</h2><small>Scheduler, queue worker, and availability</small></div></div>
                        <div class="oc-runtime-list">
                            @foreach ([['Scheduler', $overview['scheduler']], ['Queue worker', $overview['queue']]] as [$label, $service])
                                <a href="#checks" class="oc-runtime-row">
                                    <span><strong>{{ $label }}</strong><small>{{ $service['value'] ?: $service['hint'] }}</small>
                                        @if ($service['status'] !== 'healthy')<small class="oc-runtime-hint">{{ $service['hint'] }}</small>@endif
                                        @if ($service['fix'])<code class="oc-runtime-fix" dir="ltr">{{ $service['fix'] }}</code>@endif
                                    </span>
                                    <span class="oc-status-pill {{ match ($service['status']) { 'critical' => 'is-bad', 'warning' => 'is-warning', 'healthy' => 'is-ok', default => 'is-unknown' } }}">{{ $service['label'] }}</span>
                                </a>
                            @endforeach
                            <a href="{{ $overview['maintenance']['action'] }}" class="oc-runtime-row">
                                <span><strong>Maintenance mode</strong><small>{{ $overview['maintenance']['down'] ? 'Visitors receive a 503 response.' : 'Application accepts normal traffic.' }}</small></span>
                                <span class="oc-status-pill {{ $overview['maintenance']['down'] ? 'is-warning' : 'is-ok' }}">{{ $overview['maintenance']['down'] ? 'Enabled' : 'Off' }}</span>
                            </a>
                        </div>
                    </section>
                </div>
            </div>

            @if ($hasOperationsTab)
            <div class="oc-tab-panel" id="oc-panel-operations" role="tabpanel" aria-labelledby="oc-tab-operations" data-oc-panel="operations">
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
                                <ul class="oc-migrations" dir="ltr" aria-label="Pending migrations" data-oc-scroll-fade="y" tabindex="0">
                                    @foreach (array_slice($action['pending'], 0, 8) as $migration)
                                        <li>{{ $migration }}</li>
                                    @endforeach
                                    @if (count($action['pending']) > 8)
                                        <li class="oc-migrations-more">+{{ count($action['pending']) - 8 }} more pending</li>
                                    @endif
                                </ul>
                            @endif

                            @if ($action['disabledReason'])
                                <small class="oc-action-disabled-note" id="oc-action-disabled-{{ $key }}">{{ $action['disabledReason'] }}</small>
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
                        </article>
                    @endforeach
                </section>
            @endif

            {{-- Output of the last action --}}
            @if ($output)
                <section class="oc-section" aria-label="Last operation output" data-oc-output role="{{ $output['ok'] ? 'status' : 'alert' }}" aria-live="{{ $output['ok'] ? 'polite' : 'assertive' }}">
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

            @if ($hasOperationsTab)
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
                    <div class="oc-health-filter">
                        <label for="oc-health-filter">Show checks</label>
                        <select id="oc-health-filter" class="oc-input" data-oc-health-filter>
                            <option value="all">All statuses</option>
                            <option value="attention">Needs attention</option>
                            <option value="critical">Critical</option>
                            <option value="warning">Warnings</option>
                            <option value="unknown">Unverified</option>
                            <option value="healthy">Healthy</option>
                        </select>
                        <span class="oc-sr-only" data-oc-health-count aria-live="polite"></span>
                    </div>
                    @foreach ($checks['groups'] as $group)
                        <div class="oc-check-group" data-oc-check-group>
                            <h3 class="oc-check-group-title">
                                <span>{{ $group['name'] }}</span>
                                <small>{{ $group['passed'] }}/{{ $group['total'] }} healthy @if ($group['unknown']) · {{ $group['unknown'] }} unknown @endif</small>
                            </h3>
                            <ul class="oc-checks oc-checks-grid">
                                @foreach ($group['items'] as $check)
                                    <li class="{{ $check['class'] }}" data-oc-check-item data-oc-status="{{ $check['status'] }}">
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
                    <div class="oc-empty oc-filter-empty" data-oc-filter-empty @if ($checks['groups']) hidden @endif>
                        <p>No health checks match this filter.</p>
                        <button type="button" class="oc-btn oc-btn-soft-secondary" data-oc-health-reset>Show all checks</button>
                    </div>
                </div>
            </details>

            </div>

            <div class="oc-tab-panel" id="oc-panel-security" role="tabpanel" aria-labelledby="oc-tab-security" data-oc-panel="security">
                @php($securityStatus = $securityReport['status'] ?? 'never_scanned')
                @php($securityFresh = $securityStatus === 'complete' && empty($securityReport['stale']))
                @php($securityTone = $securityFresh ? (empty($securityReport['advisories']) ? 'is-ok' : 'is-bad') : ($securityStatus === 'complete' ? 'is-warning' : ($securityStatus === 'failed' ? 'is-bad' : 'is-unknown')))
                <section class="oc-section" id="security" aria-labelledby="oc-security-title">
                    <div class="oc-section-head">
                        <b>@include('opconsole::partials.icon', ['name' => 'shield'])</b>
                        <div><h2 id="oc-security-title">Security</h2><small>Host dependencies and runtime Laravel configuration</small></div>
                        <span class="oc-status-pill {{ $securityTone }} oc-ms-auto">
                            @include('opconsole::partials.icon', ['name' => match ($securityTone) { 'is-ok' => 'check-circle', 'is-bad' => 'x-circle', 'is-warning' => 'alert-circle', default => 'help-circle' }])
                            <span>{{ $securityFresh ? (empty($securityReport['advisories']) ? 'No known vulnerabilities' : count($securityReport['advisories']).' active '.(count($securityReport['advisories']) === 1 ? 'advisory' : 'advisories')) : ($securityStatus === 'complete' ? 'Stale report' : ucfirst(str_replace('_', ' ', $securityStatus))) }}</span>
                        </span>
                    </div>
                    <div class="oc-section-body oc-security">
                        <div class="oc-security-status" role="status" aria-live="polite">
                            <strong>{{ $securityFresh ? 'Completed scan' : 'Scan status: '.str_replace('_', ' ', $securityReport['status'] ?? 'never scanned') }}</strong>
                            <dl class="oc-security-facts">
                                <div><dt>Scope</dt><dd>{{ $securityReport['scope'] ?? 'composer.lock · production dependencies' }}</dd></div>
                                @if (!empty($securityReport['scanned_at']))<div><dt>Scanned</dt><dd><time datetime="{{ $securityReport['scanned_at'] }}">{{ $securityReport['scanned_at'] }}</time></dd></div>@endif
                            </dl>
                        </div>
                        @if (!empty($securityReport['manifest_changed']))<p class="oc-advice oc-advice-warning" role="alert">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<span>Dependency files changed since the last successful scan. Results are stale; run <code>php artisan opconsole:security</code>.</span></p>@endif
                        @if (!empty($securityReport['stale']) && empty($securityReport['manifest_changed']))<p class="oc-advice oc-advice-warning" role="alert">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<span>The last successful scan is older than the configured freshness window. Run <code>php artisan opconsole:security</code>.</span></p>@endif
                        @if (!empty($securityReport['last_failure']))<p class="oc-advice oc-advice-danger" role="alert">@include('opconsole::partials.icon', ['name' => 'alert-octagon'])<span>Latest scan failed @if(!empty($securityReport['last_failure']['at']))<time datetime="{{ $securityReport['last_failure']['at'] }}">{{ $securityReport['last_failure']['at'] }}</time>@endif: {{ $securityReport['last_failure']['message'] }}. Last successful report: {{ $securityReport['last_success_at'] ?? 'none' }}.</span></p>@endif
                        @if (!empty($securityReport['version_mismatches']))<div class="oc-advice oc-advice-warning" role="status">@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<div><strong>Installed and locked dependency versions differ:</strong><ul>@foreach($securityReport['version_mismatches'] as $mismatch)<li><code>{{ $mismatch['package'] }}</code> · lock {{ $mismatch['locked_version'] }}, installed {{ $mismatch['installed_version'] }}</li>@endforeach</ul></div></div>@endif
                        @if ($securityFresh && empty($securityReport['advisories']))<p class="oc-advice oc-advice-ok" role="status">@include('opconsole::partials.icon', ['name' => 'check-circle'])<span>No known vulnerabilities found in scanned dependencies.</span></p>@elseif (!$securityFresh)<p class="oc-advice oc-advice-warning" role="status">@include('opconsole::partials.icon', ['name' => 'help-circle'])<span>A clean result cannot be confirmed until a current scan completes.</span></p>@endif
                        @if ($securityFresh)
                            @php($securityCounts = collect($securityReport['advisories'] ?? [])->groupBy(fn ($advisory) => $advisory['severity'] ?? 'not supplied')->map->count())
                            <ul class="oc-severity-stats" aria-label="Vulnerability counts by severity">
                                @foreach (['critical', 'high', 'medium', 'low', 'not supplied'] as $level)
                                    <li @class(['oc-sev', 'oc-sev-'.str_replace(' ', '-', $level), 'is-zero' => ($securityCounts[$level] ?? 0) === 0])><strong>{{ $securityCounts[$level] ?? 0 }}</strong><span>{{ ucfirst($level) }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                        @if (!empty($securityReport['advisories']))
                            <div class="oc-toolbar"><label for="oc-security-search" class="oc-field"><span>Search advisories</span><input id="oc-security-search" class="oc-input" type="search" data-security-search aria-controls="oc-security-advisories"></label><label for="oc-security-severity" class="oc-field"><span>Filter severity</span><select id="oc-security-severity" class="oc-input" data-security-severity aria-controls="oc-security-advisories"><option value="all">All severities</option><option value="critical">Critical</option><option value="high">High</option><option value="medium">Medium</option><option value="low">Low</option><option value="not supplied">Not supplied</option></select></label></div>
                            <div class="oc-table-wrap" data-oc-scroll-fade="x"><table class="oc-table" id="oc-security-advisories"><thead><tr><th scope="col">Package / scanned version</th><th scope="col">Severity</th><th scope="col">Advisory</th><th scope="col">Affected versions</th><th scope="col">Published</th><th scope="col">Remediation</th></tr></thead><tbody>
                            @foreach ($securityReport['advisories'] as $advisory)<tr data-security-row data-security-level="{{ $advisory['severity'] ?? 'not supplied' }}"><td><code class="oc-pkg">{{ $advisory['package'] }}</code> <span class="oc-mono-sub">{{ $advisory['version'] ?? 'version unavailable' }}</span> @if($advisory['version_mismatch'] ?? false)<strong class="oc-mismatch"> · installed version differs</strong>@endif</td><td><span class="oc-sev-badge oc-sev-{{ str_replace(' ', '-', $advisory['severity'] ?? 'not supplied') }}">{{ $advisory['severity'] ?? 'Not supplied' }}</span></td><td>@if($advisory['link'])<a href="{{ $advisory['link'] }}" rel="noopener noreferrer" target="_blank" class="oc-inline-link">{{ $advisory['title'] }}</a>@else{{ $advisory['title'] }}@endif @if($advisory['identifiers'] ?? [])<small class="oc-mono-sub">{{ implode(', ', $advisory['identifiers']) }}</small>@endif</td><td><span class="oc-mono-sub">{{ $advisory['affected'] ?? 'Not supplied' }}</span></td><td>{{ $advisory['published'] ?? 'Not supplied' }}</td><td>{{ $advisory['remediation'] ?? 'No remediation guidance supplied; review the advisory and update through your normal dependency review process.' }}</td></tr>@endforeach
                            </tbody></table></div>
                        @endif
                        @if (!empty($securityReport['ignored']))<details class="oc-disclosure"><summary>{{ count($securityReport['ignored']) }} ignored advisory records excluded from active findings</summary><ul>@foreach($securityReport['ignored'] as $item)<li><code>{{ $item['package'] }}</code>: {{ $item['title'] }} — {{ $item['ignored_reason'] ?? 'Composer reports this advisory as ignored; reason not supplied.' }}</li>@endforeach</ul></details>@endif
                        @if (!empty($securityReport['abandoned']))<div class="oc-subsection"><h3>Abandoned packages (not confirmed vulnerabilities)</h3><ul class="oc-plain-list">@foreach($securityReport['abandoned'] as $item)<li><code>{{ $item['package'] }}</code>@if($item['replacement']) · suggested replacement: <code>{{ $item['replacement'] }}</code>@endif</li>@endforeach</ul></div>@endif
                        @if (!empty($securityReport['config_warnings']))<div class="oc-subsection"><h3>Laravel configuration warnings</h3><ul class="oc-plain-list oc-warning-list">@foreach($securityReport['config_warnings'] as $warning)<li>@include('opconsole::partials.icon', ['name' => 'alert-triangle'])<div><strong>{{ $warning['message'] }}</strong> <span>{{ $warning['fix'] }}</span></div></li>@endforeach</ul></div>@endif
                        <footer class="oc-security-foot">
                            <p>Advisory data source: {{ $securityReport['source'] ?? 'Composer repositories (Packagist by default)' }}. Composer sends package names and locked versions to configured advisory repositories; credentials are not included in this report.</p>
                            <p>Run scans with <code>php artisan opconsole:security</code>; include development dependencies with <code>--include-dev</code>. Review recommended updates in your normal development and deployment process.</p>
                        </footer>
                    </div>
                </section>
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
                        <div class="oc-table-wrap" data-oc-scroll-fade="x">
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
                                            <td class="oc-table-details">
                                                @if ($run['details'] !== '—')
                                                    <details class="oc-history-detail"><summary>View details</summary><p><bdi>{{ $run['details'] }}</bdi></p></details>
                                                @else
                                                    —
                                                @endif
                                            </td>
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
            <details class="oc-section oc-system-info" id="system-info" open>
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
                        <nav class="oc-log-files" aria-label="Log files" data-oc-scroll-fade="x">
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
                                        @if ($chip['active'])@include('opconsole::partials.icon', ['name' => 'check'])@endif
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
                            @if ($loop->first)<div class="oc-log-list">@endif
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
                            @if ($loop->last)</div>@endif
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
