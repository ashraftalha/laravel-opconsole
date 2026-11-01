@extends($layout ?? 'opconsole::layout')

{{-- No element here may use id="maintenance" or id="console": some host themes wire scripts to those ids. --}}
@section('title', 'OpConsole')

@section('content')
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            @if ($backUrl)
                <a href="{{ $backUrl }}" class="text-muted small"><i class="uil uil-arrow-left"></i> {{ $backLabel }}</a>
            @endif
            <h1 class="h4 mt-2 mb-0">OpConsole</h1>
            <p class="text-muted small mb-0">Monitor. Diagnose. Maintain.</p>
        </div>
        <a href="{{ route(config('opconsole.route.name_prefix').'index') }}" class="btn btn-soft-primary"><i class="uil uil-sync"></i> Refresh status</a>
    </header>

    <div class="oc-workspace row g-4 mt-0">
        <div class="col-12">

            {{-- Status at a glance --}}
            <div class="row row-cols-md-3 row-cols-1 g-3 mt-1">
                @foreach ($tiles as $tile)
                    <div class="col">
                        @if ($tile['link'])<a href="{{ $tile['link'] }}" class="oc-tile-link">@endif
                            <div class="oc-tile" style="--c: {{ $tile['color'] }}">
                                <span class="oc-tile-icon"><i class="uil {{ $tile['icon'] }}"></i></span>
                                <div><strong>{{ $tile['value'] }}</strong><span>{{ $tile['label'] }} · <bdi>{{ $tile['sub'] }}</bdi></span></div>
                            </div>
                            @if ($tile['link'])</a>@endif
                    </div>
                @endforeach
            </div>

            {{-- What to do next, most urgent first --}}
            <div class="oc-advice-list my-4" data-advice-count="{{ count($advice) }}">
                @forelse ($advice as $item)
                    <div class="oc-advice {{ $item['class'] }}">
                        <i class="uil {{ $item['icon'] }}"></i>
                        <span>{{ $item['text'] }}</span>
                        @if ($item['link'])<a href="{{ $item['link'][0] }}" class="oc-advice-link">{{ $item['link'][1] }} <i class="uil uil-arrow-right"></i></a>@endif
                    </div>
                @empty
                    @if ($checks['tone'] === 'is-ok')
                        <div class="oc-advice oc-advice-ok"><i class="uil uil-check-circle"></i><span>Everything looks good. No action is needed.</span></div>
                    @endif
                @endforelse
            </div>

            {{-- Actions --}}
            @if (count($actions))
                <div class="row g-3">
                    @foreach ($actions as $key => $action)
                        <div class="col-md-4">
                            <section class="oc-action" id="action-{{ $key }}">
                                <span class="oc-action-icon"><i class="uil {{ $action['icon'] }}"></i></span>
                                <h3>{{ $action['title'] }}</h3>
                                <p>{{ $action['text'] }}</p>

                                @if ($action['pending'])
                                    <ul class="oc-migrations" dir="ltr">
                                        @foreach ($action['pending'] as $migration)<li>{{ $migration }}</li>@endforeach
                                    </ul>
                                @endif

                                @if ($action['last'])
                                    <small class="oc-action-last">
                                        <i class="uil {{ $action['last']['ok'] ? 'uil-check text-success' : 'uil-times text-danger' }}"></i> {{ $action['last']['text'] }}
                                    </small>
                                @endif

                                <form method="POST" action="{{ route(config('opconsole.route.name_prefix').'run', $key) }}" data-confirm="{{ $action['confirm'] }}">
                                    @csrf
                                    <button type="submit" class="btn {{ $action['style'] }} w-100" @disabled(! $action['enabled'])><i class="uil {{ $action['icon'] }}"></i> {{ $action['button'] }}</button>
                                </form>
                            </section>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Output of the last action --}}
            @if ($output)
                <section class="oc-section mt-4">
                    <div class="oc-section-head">
                        <b><i class="uil uil-brackets-curly"></i></b>
                        <div><h3>Output: {{ $output['title'] }}</h3><small>Output from the last operation</small></div>
                    </div>
                    <div class="oc-section-body">
                        <pre class="oc-output" dir="ltr">{{ $output['text'] }}</pre>
                    </div>
                </section>
            @endif

            {{-- Health checks: problems first --}}
            <details class="oc-section mt-4 oc-check-details" id="checks">
                <summary class="oc-section-head oc-check-toggle">
                    <b><i class="uil uil-heart-rate"></i></b>
                    <div><h3>Health checks</h3><small>Application requirements, with problems listed first</small></div>
                    <span class="oc-checks-summary {{ $checks['tone'] }} ms-auto" data-checks-summary>{{ $checks['summary'] }}</span>
                    <i class="uil uil-angle-down oc-check-chevron" aria-hidden="true"></i>
                </summary>
                <div class="oc-section-body">
                    @foreach ($checks['groups'] as $group)
                        <div class="oc-check-group">
                            <h4 class="oc-check-group-title">{{ $group['name'] }} <small>{{ $group['passed'] }}/{{ $group['total'] }}</small></h4>
                            <ul class="oc-checks oc-checks-grid">
                                @foreach ($group['items'] as $check)
                                    <li class="{{ $check['class'] }}">
                                        <i class="uil {{ $check['icon'] }}"></i>
                                        <div>
                                            <strong>{{ $check['label'] }}</strong>
                                            <small>{{ $check['hint'] }}</small>
                                            @if ($check['value'])<code class="oc-check-value" dir="ltr">{{ $check['value'] }}</code>@endif
                                            @if (! $check['ok'] && $check['fix'])
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

        {{-- System info, grouped; copyable for support --}}
        <div class="col-12">
            <details class="oc-section oc-system-info">
                <summary class="oc-system-toggle"><span><i class="uil uil-info-circle"></i> System information</span><span>View details <i class="uil uil-angle-down"></i></span></summary>
                <div class="oc-section-head">
                    <b><i class="uil uil-info-circle"></i></b>
                    <div><small>Copy these details when requesting support</small></div>
                    <button type="button" class="btn btn-sm btn-soft-primary ms-auto" data-copy="{{ $infoText }}" title="Copy"><i class="uil uil-copy"></i> Copy</button>
                </div>
                <div class="oc-section-body oc-info-grid">
                    @foreach ($info as $group => $rows)
                        <div><h4 class="oc-info-group">{{ $group }}</h4>
                        <dl class="oc-info">
                            @foreach ($rows as $label => $value)
                                <div><dt>{{ $label }}</dt><dd><bdi>{{ $value }}</bdi></dd></div>
                            @endforeach
                        </dl></div>
                    @endforeach
                    @if ($dbError)
                        <small class="d-block text-danger mt-3" dir="ltr">{{ $dbError }}</small>
                    @endif
                </div>
            </details>
        </div>
    </div>

    {{-- Log files --}}
    <section class="oc-section mt-4" id="logs">
        <div class="oc-section-head">
            <b><i class="uil uil-file-alt"></i></b>
            <div><h3>System logs</h3><small>Errors and warnings, newest first</small></div>
        </div>
        <div class="oc-section-body">
            @if (! $log)
                @include('opconsole::partials.empty-state', ['message' => 'No log files yet.'])
            @else
                {{-- File picker --}}
                <div class="oc-log-files">
                    @foreach ($logFiles as $file)
                        <div @class(['oc-log-file', 'is-active' => $file['active']])>
                            <a href="{{ $file['url'] }}" class="stretched-link" aria-label="Open {{ $file['name'] }}"><i class="uil uil-file"></i></a>
                            <span dir="ltr">{{ $file['name'] }}</span>
                            <small>{{ $file['meta'] }}</small>
                            @if ($file['deletable'])
                                <form method="POST" action="{{ route(config('opconsole.route.name_prefix').'log.delete', $file['name']) }}" class="oc-log-delete" data-confirm="Delete log file {{ $file['name'] }} permanently?">
                                    @csrf @method('DELETE')
                                    <button type="submit" title="Delete file"><i class="uil uil-trash-alt"></i></button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($log['envChips'])
                    <div class="oc-log-toolbar">
                        <div class="oc-log-levels" aria-label="Environment">
                            @foreach ($log['envChips'] as $chip)
                                <a href="{{ $chip['url'] }}" @class(['oc-chip', 'is-active' => $chip['active']]) @if ($chip['env']) data-env="{{ $chip['env'] }}" @endif>{{ $chip['label'] }} <b>{{ $chip['count'] }}</b></a>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="oc-log-toolbar">
                    <div class="oc-log-levels" aria-label="Level">
                        @foreach ($log['levelChips'] as $chip)
                            <a href="{{ $chip['url'] }}" @class(['oc-chip', $chip['style'], 'is-active' => $chip['active']]) @if ($chip['level']) data-level="{{ $chip['level'] }}" @endif>{{ $chip['label'] }} <b>{{ $chip['count'] }}</b></a>
                        @endforeach
                    </div>
                    <form method="GET" action="{{ route(config('opconsole.route.name_prefix').'index') }}#logs" class="oc-log-search">
                        <input type="hidden" name="log" value="{{ $log['name'] }}">
                        <input type="hidden" name="env" value="{{ $log['env'] }}">
                        @if ($log['level'])<input type="hidden" name="level" value="{{ $log['level'] }}">@endif
                        <input type="search" aria-label="Search logs" name="q" value="{{ $log['search'] }}" class="form-control" placeholder="Search messages and stack traces…" maxlength="200" dir="auto">
                        <button type="submit" class="btn btn-soft-primary" title="Search"><i class="uil uil-search"></i></button>
                        @if ($log['search'] !== '')<a href="{{ $log['clearSearchUrl'] }}" class="btn btn-soft-secondary" title="Clear search"><i class="uil uil-times"></i></a>@endif
                    </form>
                    @if ($log['downloadUrl'])
                        <a href="{{ $log['downloadUrl'] }}" class="btn btn-soft-secondary"><i class="uil uil-download-alt"></i> Download file</a>
                    @endif
                </div>

                <p class="text-muted small mb-3" data-log-summary>
                    {{ $log['summary'] }}
                    @if ($log['truncated'])· Only the last 2 MB are shown. Download the file to view older entries.@endif
                </p>

                @if ($log['groups'])
                    <details class="oc-log-groups">
                        <summary><i class="uil uil-layers"></i> Most frequent</summary>
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
                            <button type="button" class="btn btn-sm btn-soft-secondary" data-copy="{{ $entry['copy'] }}" title="Copy"><i class="uil uil-copy"></i> Copy entry</button>
                            @if ($entry['detailsHtml'])
                                <pre class="oc-output" dir="ltr">{!! $entry['detailsHtml'] !!}</pre>
                            @endif
                        </div>
                    </details>
                @empty
                    @include('opconsole::partials.empty-state', ['message' => 'No entries match this search.'])
                @endforelse

                @if ($log['moreUrl'])
                    <a href="{{ $log['moreUrl'] }}" class="btn btn-soft-primary w-100 mt-2"><i class="uil uil-angle-down"></i> Show more</a>
                @endif
            @endif
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const checks = document.getElementById('checks');
            const revealChecks = () => {
                if (window.location.hash === '#checks') checks.open = true;
            };
            revealChecks();
            window.addEventListener('hashchange', revealChecks);
            document.querySelectorAll('a[href="#checks"]').forEach(link => {
                link.addEventListener('click', () => { checks.open = true; });
            });
        })();
    </script>
@endpush
