<?php

namespace AshrafTalha\OpConsole\Http\Controllers;

use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Support\ConsoleStatus;
use AshrafTalha\OpConsole\Support\LogViewer;
use AshrafTalha\OpConsole\Support\PagePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
    ];

    public function __construct(
        private ConsoleStatus $status,
        private LogViewer $logs,
        private PagePresenter $presenter,
        private ActionRunner $actions,
    ) {}

    public function index(Request $request): View
    {
        return view('opconsole::index', $this->presenter->page(
            $this->status->status($request->getHost()),
            $this->logs->files(),
            $this->log($request),
            session('opconsole_output'),
        ) + ['layout' => config('opconsole.layout')]);
    }

    public function downloadLog(string $file): BinaryFileResponse
    {
        abort_unless(config('opconsole.logs.allow_download', true), 403);

        $this->actions->record('log.download', request()->user(), true, $file);

        return $this->logs->download($file);
    }

    public function deleteLog(string $file): RedirectResponse
    {
        abort_unless(config('opconsole.logs.allow_delete', true), 403);

        $ok = $this->logs->delete($file);
        $this->actions->record('log.delete', request()->user(), $ok, $file);

        return $ok
            ? redirect()->to(route($this->routeName('index')).'#logs')->with('opconsole_success', __('opconsole::opconsole.log_deleted', ['file' => $file]))
            : redirect()->to(route($this->routeName('index')).'#logs')->with('opconsole_error', __('opconsole::opconsole.log_delete_latest_blocked'));
    }

    public function run(Request $request, string $action): RedirectResponse
    {
        if (! array_key_exists($action, self::MESSAGES) || ! config("opconsole.actions.{$action}")) {
            abort(404);
        }

        $result = $this->actions->run($action, $request->user());
        [$successKey, $errorKey] = self::MESSAGES[$action];
        $flash = $result['busy'] ? 'opconsole_error' : ($result['ok'] ? 'opconsole_success' : 'opconsole_error');
        $message = $result['busy'] ? $result['output'] : __('opconsole::opconsole.'.($result['ok'] ? $successKey : $errorKey));

        return redirect()->route($this->routeName('index'))
            ->with($flash, $message)
            ->with('opconsole_output', ['action' => $action, 'text' => $result['output']]);
    }

    /**
     * The open log file and its filters, from the query string.
     * ?env= defaults to this app's environment, so test runs and other environments don't drown out real errors.
     */
    private function log(Request $request): ?array
    {
        $file = $this->logs->path($request->query('log')) ? $request->query('log') : ($this->logs->files()[0]['name'] ?? null);
        if (! $file) {
            return null;
        }

        $level = in_array($request->query('level'), LogViewer::LEVELS, true) ? $request->query('level') : null;
        $search = Str::limit(trim((string) $request->query('q')), 200, '');
        $limit = min(500, max(50, (int) $request->query('limit', 50)));
        $env = match ($request->query('env')) {
            'all' => null,
            null, '' => app()->environment(),
            default => (string) $request->query('env'),
        };

        $result = $this->logs->read($file, $level, $env, $search, $limit);
        if ($env !== null && ! isset($result['envs'][$env])) {
            $env = null; // nothing logged in that environment: show everything instead of an empty list
            $result = $this->logs->read($file, $level, null, $search, $limit);
        }

        return ['name' => $file, 'level' => $level, 'env' => $env, 'search' => $search, 'limit' => $limit] + $result;
    }

    private function routeName(string $suffix): string
    {
        return config('opconsole.route.name_prefix', 'opconsole.').$suffix;
    }
}
