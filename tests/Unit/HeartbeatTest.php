<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Support\Heartbeat;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class HeartbeatTest extends TestCase
{
    public function test_beats_update_independently_in_the_configured_store(): void
    {
        config(['cache.stores.heartbeats' => ['driver' => 'array'], 'opconsole.heartbeats.store' => 'heartbeats']);
        $heartbeat = new Heartbeat;
        $this->assertSame(['at' => null, 'error' => null], $heartbeat->last(Heartbeat::SCHEDULER));

        $this->travelTo(now()->startOfSecond());
        $first = now()->getTimestamp();
        $heartbeat->beat(Heartbeat::SCHEDULER);
        $heartbeat->beat(Heartbeat::QUEUE);
        $this->travel(60)->seconds();
        $heartbeat->beat(Heartbeat::SCHEDULER);

        $this->assertSame(['at' => $first + 60, 'error' => null], $heartbeat->last(Heartbeat::SCHEDULER));
        $this->assertSame(['at' => $first, 'error' => null], $heartbeat->last(Heartbeat::QUEUE));
        $this->assertNull(Cache::store('array')->get($heartbeat->key(Heartbeat::SCHEDULER)));
    }

    public function test_beat_once_preserves_the_first_dispatch_time(): void
    {
        $heartbeat = new Heartbeat;
        $this->travelTo(now()->startOfSecond());
        $first = now()->getTimestamp();
        $heartbeat->beatOnce(Heartbeat::QUEUE_DISPATCHED);
        $this->travel(2)->days();
        $heartbeat->beatOnce(Heartbeat::QUEUE_DISPATCHED);

        $this->assertSame(['at' => $first, 'error' => null], $heartbeat->last(Heartbeat::QUEUE_DISPATCHED));
    }

    #[DataProvider('storedValues')]
    public function test_last_handles_missing_malformed_and_numeric_values(mixed $value, ?int $expected): void
    {
        $heartbeat = new Heartbeat;
        Cache::store($heartbeat->storeName())->forever($heartbeat->key(Heartbeat::QUEUE), $value);

        $this->assertSame(['at' => $expected, 'error' => null], $heartbeat->last(Heartbeat::QUEUE));
    }

    public static function storedValues(): array
    {
        return [
            'missing' => [null, null],
            'invalid text' => ['not a timestamp', null],
            'array' => [[123], null],
            'boolean' => [true, null],
            'numeric string' => ['1700000000', 1700000000],
            'integer' => [1700000000, 1700000000],
            'zero' => [0, 0],
        ];
    }

    public function test_cache_failure_returns_a_sanitized_error(): void
    {
        $store = $this->createMock(Repository::class);
        $store->method('get')->willThrowException(new RuntimeException('Connection failed: password=private-value'));
        Cache::shouldReceive('store')->once()->with('array')->andReturn($store);

        $this->assertSame(['at' => null, 'error' => 'Connection failed: password=[REDACTED]'], (new Heartbeat)->last(Heartbeat::QUEUE));
    }

    #[DataProvider('invalidOverrides')]
    public function test_invalid_overrides_use_defaults(mixed $value): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'database',
            'opconsole.heartbeats.store' => $value,
            'opconsole.heartbeats.queue_connection' => $value,
            'opconsole.heartbeats.queue_name' => $value,
        ]);
        $heartbeat = new Heartbeat;

        $this->assertSame('array', $heartbeat->storeName());
        $this->assertSame('database', $heartbeat->queueConnection());
        $this->assertNull($heartbeat->queueName());
    }

    public static function invalidOverrides(): array
    {
        return [[null], [''], [false], [42], [[]]];
    }

    public function test_explicit_queue_configuration_is_used(): void
    {
        config(['opconsole.heartbeats.queue_connection' => 'redis', 'opconsole.heartbeats.queue_name' => 'monitoring']);
        $heartbeat = new Heartbeat;

        $this->assertSame('redis', $heartbeat->queueConnection());
        $this->assertSame('monitoring', $heartbeat->queueName());
    }
}
