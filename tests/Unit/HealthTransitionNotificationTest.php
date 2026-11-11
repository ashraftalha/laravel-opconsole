<?php

namespace AshrafTalha\OpConsole\Tests\Unit;

use AshrafTalha\OpConsole\Notifications\HealthTransitionNotification;
use AshrafTalha\OpConsole\Notifications\HealthWebhookChannel;
use AshrafTalha\OpConsole\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class HealthTransitionNotificationTest extends TestCase
{
    #[DataProvider('channels')]
    public function test_only_configured_channels_are_selected(mixed $channels, array $expected): void
    {
        config(['opconsole.notifications.channels' => $channels]);

        $this->assertSame($expected, (new HealthTransitionNotification('unhealthy', []))->via(new stdClass));
    }

    public static function channels(): array
    {
        return [
            'invalid config' => ['invalid', []],
            'empty config' => [[], []],
            'empty destinations' => [['email' => '', 'webhooks' => [], 'slack' => []], []],
            'mail' => [['email' => 'ops@example.test'], ['mail']],
            'webhook' => [['webhooks' => ['https://example.test/hook']], [HealthWebhookChannel::class]],
            'slack' => [['slack' => ['https://example.test/slack']], [HealthWebhookChannel::class]],
            'all destinations' => [['email' => 'ops@example.test', 'webhooks' => ['https://example.test/hook'], 'slack' => ['https://example.test/slack']], ['mail', HealthWebhookChannel::class]],
        ];
    }

    public function test_payload_excludes_healthy_checks_and_private_details_and_redacts_secrets(): void
    {
        $notification = new HealthTransitionNotification('unhealthy', [
            'status' => 'critical',
            'checked_at' => '2026-01-01T12:00:00+00:00',
            'checks' => [
                ['id' => 'healthy', 'status' => 'healthy', 'label' => 'Healthy check', 'value' => 'ok'],
                ['id' => 'token=private-id', 'status' => 'critical', 'label' => 'Database password=private-label', 'value' => 'redis://user:private-password@localhost', 'details' => 'private-details'],
                ['id' => 'queue', 'status' => 'warning', 'label' => 'Queue', 'value' => null],
            ],
        ]);

        $this->assertSame([
            'event' => 'unhealthy',
            'status' => 'critical',
            'checked_at' => '2026-01-01T12:00:00+00:00',
            'checks' => [
                ['id' => 'token=[REDACTED]', 'status' => 'critical', 'label' => 'Database password=[REDACTED]', 'value' => 'redis://user:[REDACTED]@localhost'],
                ['id' => 'queue', 'status' => 'warning', 'label' => 'Queue', 'value' => null],
            ],
        ], $notification->payload());
    }

    public function test_recovery_payload_has_no_healthy_check_details(): void
    {
        $notification = new HealthTransitionNotification('recovery', [
            'status' => 'healthy', 'checked_at' => '2026-01-01T12:00:00+00:00',
            'checks' => [['id' => 'database', 'status' => 'healthy', 'label' => 'Database', 'value' => 'ok']],
        ]);

        $this->assertSame([], $notification->payload()['checks']);
        $mail = $notification->toMail(new stdClass);
        $this->assertSame('OpConsole health: Recovery', $mail->subject);
        $this->assertSame(['Health status changed to HEALTHY.', 'Event: recovery', 'Checked at: 2026-01-01T12:00:00+00:00'], $mail->introLines);
    }
}
