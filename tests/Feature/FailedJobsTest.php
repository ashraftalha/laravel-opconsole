<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class FailedJobsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowOpConsole();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->withoutCsrfMiddleware();

        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $this->app['config']->set('queue.failed', ['driver' => 'database-uuids', 'database' => 'testing', 'table' => 'failed_jobs']);
        $this->app->forgetInstance('queue.failer');

        Schema::create('failed_jobs', function ($table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        Schema::create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    private function failJob(string $name = 'App\\Jobs\\SendInvoice', string $secret = 'card-4242-secret'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'emails',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['secret' => $secret], 'attempts' => 3]),
            'exception' => "RuntimeException: SMTP connection refused in /app/Mail.php:12\nStack trace:\n#0 {main}",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_failed_jobs_tab_lists_jobs_without_exposing_payloads(): void
    {
        $uuid = $this->failJob();
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('id="oc-tab-jobs"', false)
            ->assertSee('id="failed-jobs"', false)
            ->assertSee('App\\Jobs\\SendInvoice')
            ->assertSee('RuntimeException: SMTP connection refused')
            ->assertSee('/admin/opconsole/failed-jobs/'.$uuid.'/retry', false)
            ->assertSee('data-confirm-risk="medium"', false)
            ->assertDontSee('card-4242-secret')
            ->assertDontSee('Stack trace');
    }

    public function test_retry_requeues_the_job_removes_it_from_the_store_and_is_audited(): void
    {
        $uuid = $this->failJob();
        $this->actingAsUser();

        $this->post('/admin/opconsole/failed-jobs/'.$uuid.'/retry')
            ->assertRedirect()
            ->assertSessionHas('opconsole_success');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'emails')->count());
        $this->assertDatabaseHas('opconsole_runs', ['action' => 'queue.retry', 'status' => 'succeeded', 'subject' => $uuid]);
    }

    public function test_retry_rejects_unknown_ids_and_view_only_users(): void
    {
        $uuid = $this->failJob();
        $this->actingAsUser();

        $this->post('/admin/opconsole/failed-jobs/'.Str::uuid().'/retry')->assertNotFound();

        Gate::define('runOpConsoleActions', fn () => false);
        $this->post('/admin/opconsole/failed-jobs/'.$uuid.'/retry')->assertForbidden();
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('read-only: your account cannot retry jobs')
            ->assertDontSee('/failed-jobs/'.$uuid.'/retry', false);
    }

    public function test_a_queue_retry_action_gate_can_narrow_who_retries(): void
    {
        $uuid = $this->failJob();
        $this->app['config']->set('opconsole.authorization.action_gates', ['queue_retry' => 'retryJobs']);
        Gate::define('retryJobs', fn () => false);
        $this->actingAsUser();

        $this->post('/admin/opconsole/failed-jobs/'.$uuid.'/retry')->assertForbidden();
        $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
    }

    #[DataProvider('retryPermissionCases')]
    public function test_retry_route_and_button_enforce_both_permissions(bool $canRun, array $actionGates, bool $canRetry, bool $allowed): void
    {
        $uuid = $this->failJob();
        $this->actingAsUser();
        Gate::define('runOpConsoleActions', fn () => $canRun);
        Gate::define('retryJobs', fn () => $canRetry);
        config(['opconsole.authorization.action_gates' => $actionGates]);
        $url = '/admin/opconsole/failed-jobs/'.$uuid.'/retry';

        $dashboard = $this->get('/admin/opconsole')->assertOk();
        if ($allowed) {
            $dashboard->assertSee($url, false);
            $this->post($url)->assertRedirect()->assertSessionHas('opconsole_success');
            $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
            $this->assertSame(1, DB::table('jobs')->count());
            $this->assertDatabaseHas('opconsole_runs', ['action' => 'queue.retry', 'subject' => $uuid, 'status' => 'succeeded']);
        } else {
            $dashboard->assertDontSee($url, false)->assertSee('read-only: your account cannot retry jobs');
            $this->post($url)->assertForbidden();
            $this->assertDatabaseHas('failed_jobs', ['uuid' => $uuid]);
            $this->assertSame(0, DB::table('jobs')->count());
            $this->assertDatabaseMissing('opconsole_runs', ['action' => 'queue.retry', 'subject' => $uuid]);
        }
    }

    public static function retryPermissionCases(): array
    {
        return [
            'unmapped uses run permission' => [true, [], false, true],
            'both gates allow' => [true, ['queue_retry' => 'retryJobs'], true, true],
            'run denied despite retry permission' => [false, ['queue_retry' => 'retryJobs'], true, false],
            'retry denied despite run permission' => [true, ['queue_retry' => 'retryJobs'], false, false],
            'both gates deny' => [false, ['queue_retry' => 'retryJobs'], false, false],
            'undefined retry gate' => [true, ['queue_retry' => 'missingRetryGate'], true, false],
            'empty retry gate' => [true, ['queue_retry' => ''], true, false],
            'empty action key cannot bypass run' => [false, ['' => 'retryJobs', 'queue_retry' => 'retryJobs'], true, false],
        ];
    }

    public function test_retry_can_be_turned_off_and_the_tab_can_be_hidden(): void
    {
        $uuid = $this->failJob();
        $this->actingAsUser();

        $this->app['config']->set('opconsole.failed_jobs.allow_retry', false);
        $this->post('/admin/opconsole/failed-jobs/'.$uuid.'/retry')->assertForbidden();
        $this->get('/admin/opconsole')->assertOk()->assertDontSee('/failed-jobs/'.$uuid.'/retry', false);

        $this->app['config']->set('opconsole.failed_jobs.enabled', false);
        $this->get('/admin/opconsole')->assertOk()->assertDontSee('id="oc-tab-jobs"', false);
    }

    public function test_null_failed_job_driver_explains_that_jobs_are_not_stored(): void
    {
        $this->app['config']->set('queue.failed.driver', 'null');
        $this->app->forgetInstance('queue.failer');
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()->assertSee('Failed jobs are not being stored');
    }

    public function test_system_information_shows_extensions_performance_and_database_groups(): void
    {
        $this->actingAsUser();

        $this->get('/admin/opconsole')->assertOk()
            ->assertSee('PHP extensions')
            ->assertSee('Performance')
            ->assertSee('Server version')
            ->assertSee('Failed jobs store');
    }
}
