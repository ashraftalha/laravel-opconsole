<?php

namespace AshrafTalha\OpConsole\Tests\Feature;

use AshrafTalha\OpConsole\Notifications\HealthTransitionNotification;
use AshrafTalha\OpConsole\Support\HealthNotifier;
use AshrafTalha\OpConsole\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

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

    public function test_worsening_and_recovery_transitions_are_notified(): void
    {
        Notification::fake();
        config([
            'cache.stores.test_notifications' => ['driver' => 'array'],
            'opconsole.notifications.enabled' => true,
            'opconsole.notifications.state_store' => 'test_notifications',
            'opconsole.notifications.channels' => ['webhooks' => ['https://example.test/hook']],
            'opconsole.notifications.cooldown_seconds' => 3600,
        ]);
        $notifier = app(HealthNotifier::class);

        $notifier->evaluate($this->report('warning'));
        $notifier->evaluate($this->report('critical'));
        $notifier->evaluate($this->report('healthy'));

        Notification::assertSentOnDemandTimes(HealthTransitionNotification::class, 3);
        Notification::assertSentOnDemand(HealthTransitionNotification::class, fn ($notification) => $notification->payload()['event'] === 'unhealthy');
        Notification::assertSentOnDemand(HealthTransitionNotification::class, fn ($notification) => $notification->payload()['event'] === 'worsening');
        Notification::assertSentOnDemand(HealthTransitionNotification::class, fn ($notification) => $notification->payload()['event'] === 'recovery');
    }

    /** Real channels from here on: only the transports are faked. */
    private function enableRealChannels(array $channels): void
    {
        config([
            'cache.stores.test_notifications' => ['driver' => 'array'],
            'opconsole.notifications.enabled' => true,
            'opconsole.notifications.state_store' => 'test_notifications',
            'opconsole.notifications.channels' => $channels,
            'logging.default' => 'null',
        ]);
    }

    /** Collects every logged message with its JSON-encoded context. */
    private function captureLogs(): \ArrayObject
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $logged->append($event->message.' '.json_encode($event->context)));

        return $logged;
    }

    public function test_a_failing_mailer_does_not_block_webhook_delivery(): void
    {
        Mail::extend('failing', fn () => new FailingTransport);
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        $this->enableRealChannels(['email' => 'ops@example.test', 'webhooks' => ['https://hooks.example.test/health']]);
        Http::fake(['*' => Http::response('', 200)]);

        $notifier = app(HealthNotifier::class);
        $notifier->evaluate($this->report('critical'));
        $notifier->evaluate($this->report('critical'));

        // Delivered once; the transition counts as sent because one channel succeeded (no per-minute storm).
        Http::assertSentCount(1);
    }

    public function test_one_failing_webhook_does_not_block_the_others(): void
    {
        $this->enableRealChannels(['webhooks' => ['https://down.example.test/hook', 'https://up.example.test/hook'], 'slack' => ['https://slack.example.test/hook']]);
        Http::fake([
            'down.example.test/*' => Http::response('', 500),
            '*' => Http::response('', 200),
        ]);

        app(HealthNotifier::class)->evaluate($this->report('warning'));

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://slack.example.test/hook' && isset($request['text']));
    }

    public function test_when_every_channel_fails_the_transition_is_retried_on_the_next_run(): void
    {
        $this->enableRealChannels(['webhooks' => ['https://hooks.example.test/health']]);
        Http::fakeSequence()->push('', 503)->push('', 200);

        $notifier = app(HealthNotifier::class);
        $notifier->evaluate($this->report('critical'));
        $notifier->evaluate($this->report('critical'));
        $notifier->evaluate($this->report('critical'));

        // Failed, retried and delivered, then suppressed as already sent.
        Http::assertSentCount(2);
    }

    public function test_redirects_are_not_followed_and_count_as_failed_delivery(): void
    {
        $this->enableRealChannels(['webhooks' => ['https://hooks.example.test/health']]);
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        app(HealthNotifier::class)->evaluate($this->report('critical'));

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    public function test_non_http_destinations_are_never_contacted(): void
    {
        $this->enableRealChannels(['webhooks' => ['file:///etc/passwd', 'ftp://example.test/hook', 'not a url']]);
        Http::fake();

        app(HealthNotifier::class)->evaluate($this->report('critical'));

        Http::assertNothingSent();
    }

    /** Regression: transport exceptions embed the full URL, and webhook URLs (Slack) are credentials. */
    public function test_webhook_urls_never_reach_the_application_log(): void
    {
        $this->enableRealChannels(['slack' => ['https://hooks.slack.test/services/T000/B000/SECRETTOKEN']]);
        $logged = $this->captureLogs();
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host for https://hooks.slack.test/services/T000/B000/SECRETTOKEN'));

        app(HealthNotifier::class)->evaluate($this->report('critical'));

        $this->assertNotEmpty($logged->getArrayCopy(), 'The failure itself must still be logged.');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString('SECRETTOKEN', $line);
        }
    }

    private function report(string $status): array
    {
        return ['status' => $status, 'checked_at' => now()->toIso8601String(), 'checks' => []];
    }
}

class FailingTransport extends AbstractTransport
{
    protected function doSend(SentMessage $message): void
    {
        throw new TransportException('Connection to smtp://ops:hunter2@mail.example.test failed');
    }

    public function __toString(): string
    {
        return 'failing://';
    }
}
