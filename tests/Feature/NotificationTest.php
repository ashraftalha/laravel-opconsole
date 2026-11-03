<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Notifications\HealthTransitionNotification;
use AshrafTalha\OpConsole\Support\HealthNotifier;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Support\Facades\Notification;

class NotificationTest extends TestCase
{
    public function test_health_transition_alerts_are_disabled_by_default(): void
    {
        Notification::fake();
        app(HealthNotifier::class)->evaluate($this->report('critical'));
        Notification::assertNothingSent();
    }

    public function test_transition_alerts_are_suppressed_for_duplicate_statuses(): void
    {
        Notification::fake();
        config([
            'cache.stores.test_notifications' => ['driver' => 'array'],
            'opconsole.notifications.enabled' => true,
            'opconsole.notifications.state_store' => 'test_notifications',
            'opconsole.notifications.channels' => ['webhooks' => ['https://example.test/hook']],
        ]);
        $notifier = app(HealthNotifier::class);
        $notifier->evaluate($this->report('warning'));
        $notifier->evaluate($this->report('warning'));

        Notification::assertSentOnDemandTimes(HealthTransitionNotification::class, 1);
    }

    private function report(string $status): array
    {
        return ['status' => $status, 'checked_at' => now()->toIso8601String(), 'checks' => []];
    }
}
