<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Models\OpConsoleRun;
use AshrafTalha\OpConsole\Support\ActionRunner;
use AshrafTalha\OpConsole\Tests\Support\InspectorState;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

/**
 * The Swoole guard and the Octane operation, end to end with the real Runtime: only the
 * Octane classes (tests/Stubs/octane.php) and the per-request LARAVEL_OCTANE flag are simulated.
 */
class OctaneGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../Stubs/octane.php';
        InspectorState::reset();
        $this->serverBackup = $_SERVER;
        unset($_SERVER['LARAVEL_OCTANE']);

        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->withoutCsrfMiddleware();
        $this->app['config']->set('octane', ['server' => 'swoole', 'https' => false, 'garbage' => 50, 'swoole' => ['options' => []]]);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        InspectorState::reset();
        parent::tearDown();
    }

    /** The markup of one operation card, from its id to the end of its <article>. */
    private function card(string $html, string $action): string
    {
        $start = strpos($html, 'id="action-'.$action.'"');
        $this->assertNotFalse($start, 'card '.$action.' is rendered');

        return substr($html, $start, strpos($html, '</article>', $start) - $start);
    }

    private function insideSwooleWorker(): void
    {
        $_SERVER['LARAVEL_OCTANE'] = 1;
    }

    public function test_timed_operations_are_refused_inside_a_swoole_worker_and_the_refusal_is_audited(): void
    {
        $this->insideSwooleWorker();
        $this->actingAsUser();

        $this->post('/admin/opconsole/clear')->assertSessionHas('opconsole_error', fn ($message) => str_contains($message, 'Swoole worker')
            && str_contains($message, 'php artisan optimize:clear')
            && str_contains($message, 'OPCONSOLE_OCTANE_ALLOW_ACTIONS'));

        $run = OpConsoleRun::query()->latest('id')->firstOrFail();
        $this->assertSame('clear', $run->action);
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Blocked', (string) $run->error);
    }

    public function test_the_operations_tab_explains_the_block_before_any_click(): void
    {
        $this->insideSwooleWorker();
        $this->actingAsUser();

        $html = $this->get('/admin/opconsole')->assertOk()->getContent();

        $this->assertStringContainsString('served by a Swoole worker', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*disabled/', $this->card($html, 'clear'));
        $reloadCard = $this->card($html, 'octane_reload');
        $this->assertMatchesRegularExpression('/<button type="submit"(?![^>]*disabled)[^>]*>/', $reloadCard, 'reload stays usable inside Swoole');
    }

    public function test_octane_reload_still_runs_inside_a_swoole_worker(): void
    {
        $this->insideSwooleWorker();
        $this->actingAsUser();
        // As in a real worker: Octane's provider registered none of its commands.
        $this->assertArrayNotHasKey('octane:reload', Artisan::all());

        $this->post('/admin/opconsole/octane_reload')->assertSessionHas('opconsole_success');

        $this->assertContains('reload', InspectorState::$called);
        $this->assertDatabaseHas('opconsole_runs', ['action' => 'octane_reload', 'status' => 'succeeded']);
    }

    public function test_an_already_registered_octane_reload_command_is_used_as_is(): void
    {
        $this->actingAsUser();
        $ran = false;
        Artisan::command('octane:reload', function () use (&$ran) {
            $ran = true;
        });

        $this->post('/admin/opconsole/octane_reload')->assertSessionHas('opconsole_success');

        $this->assertTrue($ran);
        $this->assertNotContains('reload', InspectorState::$called);
    }

    public function test_the_guard_only_applies_to_swoole_and_can_be_overridden(): void
    {
        $runner = app(ActionRunner::class);
        $hasPcntl = function_exists('pcntl_alarm') && function_exists('pcntl_async_signals');

        $this->assertStringNotContainsString('Swoole', (string) $runner->unsupportedRuntimeReason('clear'), 'not inside Octane');

        $this->insideSwooleWorker();
        $this->assertStringContainsString('Swoole worker', (string) $runner->unsupportedRuntimeReason('migrate'));

        foreach (['roadrunner', 'frankenphp'] as $server) {
            $this->app['config']->set('octane.server', $server);
            $this->assertStringNotContainsString('Swoole', (string) $runner->unsupportedRuntimeReason('clear'), $server);
        }

        $this->app['config']->set('octane.server', 'swoole');
        $this->app['config']->set('opconsole.octane.allow_actions_in_swoole', true);
        $reason = $runner->unsupportedRuntimeReason('clear');
        $this->assertStringNotContainsString('Swoole', (string) $reason);
        if ($hasPcntl) {
            $this->assertNull($reason);
        }
    }

    public function test_reload_card_follows_the_real_inspector(): void
    {
        $this->actingAsUser();

        InspectorState::$running = true;
        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('id="action-octane_reload"', false)
            ->assertDontSee('No Octane server is running for this application.');

        InspectorState::$running = false;
        $this->get('/admin/opconsole')->assertOk()->assertSee('No Octane server is running for this application.');

        // Unknown is not "stopped": the button stays usable and octane:reload reports the real state.
        InspectorState::$running = new \RuntimeException('no state file');
        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('id="action-octane_reload"', false)
            ->assertDontSee('No Octane server is running for this application.');

        $this->app['config']->set('opconsole.actions.octane_reload', false);
        $this->get('/admin/opconsole')->assertOk()->assertDontSee('id="action-octane_reload"', false);
    }

    public function test_reload_reminder_only_follows_successful_cache_and_schema_changes(): void
    {
        $this->actingAsUser();
        $reminder = 'Octane workers still hold the old configuration in memory.';

        foreach (['clear', 'optimize', 'migrate'] as $action) {
            $this->withSession(['opconsole_output' => ['action' => $action, 'text' => 'done', 'ok' => true]])
                ->get('/admin/opconsole')->assertSee($reminder);
        }

        $this->withSession(['opconsole_output' => ['action' => 'clear', 'text' => 'failed', 'ok' => false]])
            ->get('/admin/opconsole')->assertDontSee($reminder);
        $this->withSession(['opconsole_output' => ['action' => 'security_scan', 'text' => 'done', 'ok' => true]])
            ->get('/admin/opconsole')->assertDontSee($reminder);

        $this->app['config']->set('octane', null);
        $this->withSession(['opconsole_output' => ['action' => 'clear', 'text' => 'done', 'ok' => true]])
            ->get('/admin/opconsole')->assertDontSee($reminder);
    }
}
