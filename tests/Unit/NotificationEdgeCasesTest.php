<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Notifications\HealthTransitionNotification;
use AshrafTalha\OpConsole\Notifications\HealthWebhookChannel;
use AshrafTalha\OpConsole\Support\HealthNotifier;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery;

class NotificationEdgeCasesTest extends TestCase
{
    public function test_notifications_skip_nonlocking_or_busy_stores(): void
    {
        config(['opconsole.notifications.enabled' => true, 'opconsole.notifications.state_store' => 'array']);
        Notification::fake();
        $lock = Cache::store('array')->lock('opconsole:health-notifications', 120);
        $this->assertTrue($lock->get());
        (new HealthNotifier)->evaluate(['status' => 'critical']);
        Notification::assertNothingSent();
        $lock->release();
        Cache::shouldReceive('store')->andReturn(new Repository($this->createMock(Store::class)));
        Log::spy();
        (new HealthNotifier)->evaluate(['status' => 'critical']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_unavailable_notification_store_is_logged_without_throwing(): void
    {
        config(['opconsole.notifications.enabled' => true, 'opconsole.notifications.state_store' => 'missing']);
        Log::spy();
        (new HealthNotifier)->evaluate(['status' => 'critical']);
        Log::shouldHaveReceived('warning')->with('OpConsole: health notification could not be evaluated', Mockery::type('array'))->once();
    }

    public function test_webhook_channel_without_routes_does_not_send(): void
    {
        Http::fake();
        (new HealthWebhookChannel)->send(new \stdClass, new HealthTransitionNotification('recovery', []));
        Http::assertNothingSent();
    }
}
