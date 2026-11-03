<?php

namespace AshrafTalha\OpConsole\Notifications;

use AshrafTalha\OpConsole\Support\ActionRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class HealthTransitionNotification extends Notification
{
    use Queueable;

    public function __construct(private string $event, private array $report) {}

    public function via(object $notifiable): array
    {
        $channels = config('opconsole.notifications.channels', []);
        $via = [];
        if (is_array($channels) && ! empty($channels['email'])) {
            $via[] = 'mail';
        }
        if (is_array($channels) && (! empty($channels['webhooks']) || ! empty($channels['slack']))) {
            $via[] = HealthWebhookChannel::class;
        }

        return $via;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('OpConsole health: '.ucfirst($this->event))
            ->line('Health status changed to '.strtoupper($this->report['status']).'.')
            ->line('Event: '.$this->event)
            ->line('Checked at: '.$this->report['checked_at']);
    }

    public function payload(): array
    {
        return [
            'event' => $this->event,
            'status' => $this->report['status'],
            'checked_at' => $this->report['checked_at'],
            'checks' => array_map(fn (array $check) => [
                'id' => ActionRunner::sanitizeText((string) $check['id']),
                'status' => (string) $check['status'],
                'label' => ActionRunner::sanitizeText((string) $check['label']),
                'value' => $check['value'] !== null ? ActionRunner::sanitizeText((string) $check['value']) : null,
            ], array_values(array_filter($this->report['checks'], fn (array $c) => $c['status'] !== 'healthy'))),
        ];
    }
}
