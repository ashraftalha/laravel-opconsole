<?php

namespace AshrafTalha\OpConsole\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Timestamps written by the scheduler and queue workers so the dashboard can tell whether they are alive. */
class Heartbeat
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    public function key(string $name): string
    {
        return 'opconsole:heartbeat:'.$name;
    }

    public function beat(string $name): void
    {
        $this->store()->forever($this->key($name), now()->getTimestamp());
    }

    /**
     * Unix timestamp of the last beat, or null when it was never written. Never throws.
     *
     * @return array{at: ?int, error: ?string}
     */
    public function last(string $name): array
    {
        try {
            $value = $this->store()->get($this->key($name));

            return ['at' => is_numeric($value) ? (int) $value : null, 'error' => null];
        } catch (Throwable $e) {
            return ['at' => null, 'error' => ActionRunner::sanitizeText($e->getMessage())];
        }
    }

    public function storeName(): string
    {
        $store = config('opconsole.heartbeats.store');

        return is_string($store) && $store !== '' ? $store : (string) config('cache.default');
    }

    private function store(): Repository
    {
        return Cache::store($this->storeName());
    }
}
