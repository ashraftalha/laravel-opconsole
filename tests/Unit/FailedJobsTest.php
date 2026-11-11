<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\FailedJobs;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\NullFailedJobProvider;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class FailedJobsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::swap(Mockery::mock(Kernel::class));
    }

    private function provider(): FailedJobProviderInterface
    {
        $provider = $this->createMock(FailedJobProviderInterface::class);
        $this->app->instance('queue.failer', $provider);

        return $provider;
    }

    public function test_custom_provider_results_are_limited_without_losing_the_total(): void
    {
        $this->provider()->method('all')->willReturn([
            (object) ['id' => 7, 'payload' => json_encode(['displayName' => 'SendInvoice', 'data' => ['secret' => 'hidden']]), 'exception' => "Failure password=hidden\nPrivate stack trace", 'failed_at' => '2026-01-01T12:00:00+00:00', 'queue' => 'emails', 'connection' => 'redis'],
            (object) ['id' => 8],
        ]);

        $result = (new FailedJobs)->list(1);

        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['total']);
        $this->assertNull($result['error']);
        $this->assertSame([['id' => '7', 'name' => 'SendInvoice', 'connection' => 'redis', 'queue' => 'emails', 'failedAt' => '2026-01-01T12:00:00+00:00', 'exception' => 'Failure password=[REDACTED]']], $result['items']);
    }

    #[DataProvider('payloads')]
    public function test_incomplete_rows_have_safe_presentation_defaults(string $payload, string $name): void
    {
        $this->provider()->method('all')->willReturn([(object) ['id' => 1, 'payload' => $payload, 'failed_at' => 'invalid-date']]);

        $this->assertSame([['id' => '1', 'name' => $name, 'connection' => '', 'queue' => '', 'failedAt' => null, 'exception' => '']], (new FailedJobs)->list()['items']);
    }

    public static function payloads(): array
    {
        return [
            'invalid json' => ['{broken', 'Unknown job'],
            'scalar json' => ['42', 'Unknown job'],
            'empty object' => ['{}', 'Unknown job'],
            'job fallback' => ['{"job":"SendInvoice"}', 'SendInvoice'],
            'display name preferred' => ['{"job":"Handler","displayName":"SendInvoice"}', 'SendInvoice'],
        ];
    }

    public function test_provider_failure_is_reported_without_exposing_credentials(): void
    {
        $this->provider()->method('all')->willThrowException(new RuntimeException('Store failed token=private-value'));

        $this->assertSame(['available' => true, 'total' => 0, 'items' => [], 'error' => 'RuntimeException: Store failed token=[REDACTED]'], (new FailedJobs)->list());
    }

    public function test_null_provider_disables_listing_and_retry(): void
    {
        $this->app->instance('queue.failer', new NullFailedJobProvider);
        Artisan::shouldReceive('call')->never();
        $jobs = new FailedJobs;

        $this->assertSame(['available' => false, 'total' => 0, 'items' => [], 'error' => null], $jobs->list());
        $this->assertSame(['ok' => false, 'error' => 'The failed job no longer exists.'], $jobs->retry('1'));
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_ids_never_reach_the_provider_or_artisan(string $id): void
    {
        $this->provider()->expects($this->never())->method('find');
        Artisan::shouldReceive('call')->never();

        $this->assertFalse((new FailedJobs)->retry($id)['ok']);
    }

    public static function invalidIds(): array
    {
        return [[''], ['../1'], ['1;queue:flush'], ['one two'], [str_repeat('a', 65)]];
    }

    public function test_lookup_failure_is_treated_as_unavailable(): void
    {
        $this->provider()->method('find')->willThrowException(new RuntimeException('offline'));
        Artisan::shouldReceive('call')->never();

        $this->assertSame(['ok' => false, 'error' => 'The failed job no longer exists.'], (new FailedJobs)->retry('1'));
    }

    public function test_successful_exit_is_not_success_when_the_job_remains_in_the_store(): void
    {
        $this->provider()->expects($this->exactly(2))->method('find')->with('1')->willReturn((object) ['id' => 1]);
        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['1']])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('');

        $this->assertSame(['ok' => false, 'error' => 'queue:retry did not re-queue the job.'], (new FailedJobs)->retry('1'));
    }

    public function test_nonzero_exit_returns_sanitized_command_output(): void
    {
        $this->provider()->method('find')->willReturn((object) ['id' => 1]);
        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['1']])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Connection password=private-value');

        $this->assertSame(['ok' => false, 'error' => 'Connection password=[REDACTED]'], (new FailedJobs)->retry('1'));
    }

    public function test_retry_exception_is_sanitized(): void
    {
        $this->provider()->method('find')->willReturn((object) ['id' => 1]);
        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['1']])->andThrow(new RuntimeException('Connection token=private-value'));

        $this->assertSame(['ok' => false, 'error' => 'RuntimeException: Connection token=[REDACTED]'], (new FailedJobs)->retry('1'));
    }

    public function test_retry_succeeds_only_after_the_job_leaves_the_store(): void
    {
        $this->provider()->expects($this->exactly(2))->method('find')->with('1')->willReturnOnConsecutiveCalls((object) ['id' => 1], null);
        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => ['1']])->andReturn(0);

        $this->assertSame(['ok' => true, 'error' => null], (new FailedJobs)->retry('1'));
    }
}
