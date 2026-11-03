<?php

namespace AshrafTalha\OpConsole\Jobs;

use AshrafTalha\OpConsole\Support\Heartbeat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Proves a queue worker is consuming jobs: the scheduler dispatches it every minute. */
class OpConsoleHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->beat(Heartbeat::QUEUE);
    }
}
