<?php

namespace AshrafTalha\OpConsole\Http\Controllers;

use AshrafTalha\OpConsole\Http\Middleware\AuthorizeOpConsole;
use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Support\ConsoleStatus;
use AshrafTalha\OpConsole\Support\FailedJobs;
use AshrafTalha\OpConsole\Support\LogViewer;
use AshrafTalha\OpConsole\Support\PagePresenter;
use AshrafTalha\OpConsole\Support\SecurityScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OpConsoleController extends Controller
{
    /** Action => [success message key, failure message key] in lang/opconsole.php. */
    private const MESSAGES = [
        'clear' => ['clear_ok', 'clear_error'],
        'optimize' => ['optimize_ok', 'optimize_error'],
        'migrate' => ['migrate_ok', 'migrate_error'],
        'down' => ['down_ok', 'down_error'],
        'up' => ['up_ok', 'up_error'],
        'storage_link' => ['storage_link_ok', 'storage_link_error'],
        'security_scan' => ['security_scan_ok', 'security_scan_error'],
    ];

    private const ASSETS = [
        'opconsole.css' => 'text/css; charset=UTF-8',
        'opconsole.js' => 'application/javascript; charset=UTF-8',
        'opconsole.svg' => 'image/svg+xml',
    ];

    public function __construct(
        private ConsoleStatus $status,
        private LogViewer $logs,
        private PagePresenter $presenter,
        private ActionRunner $actions,
        private SecurityScanner $security,
        private FailedJobs $failedJobs,
    ) {}

    public function index(Request $request): View
    {
        $files = $this->logs->files();

        return view('opconsole::index', $this->presenter->page(
            $this->status->status($request->getHost()),
            $files,
            $this->log($request, $files),
            session('opconsole_output'),
            $request->user(),
            config('opconsole.failed_jobs.enabled', true) ? $this->failedJobs->list(max(1, min(500, (int) config('opconsole.failed_jobs.limit', 50)))) : null,
        ) + ['layout' => config('opconsole.layout'), 'securityReport' => $this->security->report()]);
    }

    public function asset(string $asset): BinaryFileResponse
    {
        abort_unless(array_key_exists($asset, self::ASSETS), 404);

        $path = realpath(__DIR__.'/../../../public/'.$asset);
        abort_if($path === false || ! is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::ASSETS[$asset],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadLog(Request $request, string $file): BinaryFileResponse
    {
        abort_unless(config('opconsole.logs.allow_download', true), 403);

        $path = $this->logs->path($file);
        abort_if($path === null, 404);

        $audited = $this->actions->record('log.download', $request->user(), true, $file);
        abort_if(! $audited && $this->actions->auditRequired(), 503, __('opconsole::opconsole.audit_required'));

        return response()->download($path, $file, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function deleteLog(Request $request, string $file): RedirectResponse
    {
        abort_unless(config('opconsole.logs.allow_delete', true), 403);
        abort_if($this->logs->path($file) === null, 404);

        // Audit before deleting, so the "required" policy can refuse an irreversible delete it cannot record.
        $run = $this->actions->recordStart('log.delete', $request->user(), $file);
        if ($run === null && $this->actions->auditRequired()) {
            return redirect()->to(route($this->routeName('index')).'#logs')
                ->with('opconsole_error', __('opconsole::opconsole.audit_required'));
        }

        $outcome = $this->logs->deleteOutcome($file);
        $ok = $outcome === 'deleted';
        $reason = match ($outcome) {
            'deleted' => null,
            'latest_blocked' => 'Refused to delete active newest log file',
            'not_found' => 'Log file disappeared before it could be deleted',
            default => 'Filesystem delete failed',
        };
        $audited = $this->actions->recordFinish($run, $ok, null, $reason);

        $flashKey = $ok ? 'opconsole_success' : 'opconsole_error';
        $flashMessage = match ($outcome) {
            'deleted' => __('opconsole::opconsole.log_deleted', ['file' => $file]),
            'latest_blocked' => __('opconsole::opconsole.log_delete_latest_blocked'),
            default => __('opconsole::opconsole.log_delete_failed', ['file' => $file]),
        };

        $redirect = redirect()->to(route($this->routeName('index')).'#logs')->with($flashKey, $flashMessage);

        if (! $audited) {
            $redirect->with('opconsole_warning', __('opconsole::opconsole.audit_unavailable'));
        }

        return $redirect;
    }

    /** Re-queue one failed job by ID. Audited first, so audit.policy=required can refuse it. */
    public function retryFailedJob(Request $request, string $id): RedirectResponse
    {
        abort_unless(config('opconsole.failed_jobs.enabled', true) && config('opconsole.failed_jobs.allow_retry', true), 403);
        abort_unless(AuthorizeOpConsole::allowsAction('queue_retry', $request->user()), 403);
        abort_unless($this->failedJobs->exists($id), 404);

        $back = redirect()->to(route($this->routeName('index')).'#failed-jobs');

        $run = $this->actions->recordStart('queue.retry', $request->user(), $id);
        if ($run === null && $this->actions->auditRequired()) {
            return $back->with('opconsole_error', __('opconsole::opconsole.audit_required'));
        }

        $started = hrtime(true);
        $result = $this->failedJobs->retry($id);
        $audited = $this->actions->recordFinish($run, $result['ok'], null, $result['error'], (int) ((hrtime(true) - $started) / 1_000_000));

        $back = $result['ok']
            ? $back->with('opconsole_success', __('opconsole::opconsole.queue_retry_ok', ['id' => $id]))
            : $back->with('opconsole_error', __('opconsole::opconsole.queue_retry_error', ['id' => $id, 'reason' => $result['error']]));

        return $audited ? $back : $back->with('opconsole_warning', __('opconsole::opconsole.audit_unavailable'));
    }

    public function run(Request $request, string $action): RedirectResponse
    {
        if (! array_key_exists($action, self::MESSAGES) || ! config("opconsole.actions.{$action}")) {
            abort(404);
        }

        $result = $this->actions->run($action, $request->user());
        [$successKey, $errorKey] = self::MESSAGES[$action];
        // Not started at all: show the specific reason rather than a generic "command failed" message.
        $notStarted = $result['busy'] || ($result['lock_error'] ?? false) || ($result['blocked'] ?? false);
        $flash = ($notStarted || ! $result['ok']) ? 'opconsole_error' : 'opconsole_success';
        $message = $notStarted ? $result['output'] : __('opconsole::opconsole.'.($result['ok'] ? $successKey : $errorKey));

        $redirect = redirect()->route($this->routeName('index'))
            ->with($flash, $message)
            ->with('opconsole_output', ['action' => $action, 'text' => $result['output'], 'ok' => $result['ok']]);

        if (! ($result['audited'] ?? true)) {
            $redirect->with('opconsole_warning', __('opconsole::opconsole.audit_unavailable'));
        }

        return $redirect;
    }

    /**
     * The open log file and its filters, from the query string.
     * ?env= defaults to this app's environment, so test runs and other environments don't drown out real errors.
     *
     * @param  list<array{name: string, size: int, modified: Carbon}>  $files
     */
    private function log(Request $request, array $files): ?array
    {
        $requested = $request->query('log');
        $file = (is_string($requested) && $this->logs->path($requested) !== null)
            ? $requested
            : ($files[0]['name'] ?? null);
        if (! $file) {
            return null;
        }

        $level = in_array($request->query('level'), LogViewer::LEVELS, true) ? $request->query('level') : null;
        $search = Str::limit(trim((string) $request->query('q')), 200, '');
        $limit = min(500, max(50, (int) $request->query('limit', 50)));
        $defaultedEnv = $request->query('env') === null || $request->query('env') === '';
        $env = match ($request->query('env')) {
            'all' => null,
            null, '' => app()->environment(),
            default => (string) $request->query('env'),
        };

        $result = $this->logs->read($file, $level, $env, $search, $limit, fallbackToAllEnvs: $defaultedEnv);

        return ['name' => $file, 'level' => $level, 'env' => $result['resolvedEnv'], 'search' => $search, 'limit' => $limit] + $result;
    }

    private function routeName(string $suffix): string
    {
        return config('opconsole.route.name_prefix', 'opconsole.').$suffix;
    }
}
