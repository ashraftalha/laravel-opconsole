<?php

namespace AshrafTalha\OpConsole\Notifications;

use Illuminate\Support\Facades\Http;

/** Synchronous webhook delivery keeps transport failures local to the monitoring command. */
class HealthWebhookChannel
{
    public function send(object $notifiable, HealthTransitionNotification $notification): void
    {
        $routes = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor(self::class)
            : [];
        $routes = is_array($routes) ? $routes : [];
        foreach (['webhooks', 'slack'] as $type) {
            foreach ((array) ($routes[$type] ?? []) as $url) {
                if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
                    continue;
                }
                $payload = $notification->payload();
                if ($type === 'slack') {
                    $payload = ['text' => 'OpConsole health '.$payload['event'].': '.$payload['status'], 'attachments' => [['text' => json_encode($payload, JSON_UNESCAPED_SLASHES)]]];
                }
                Http::timeout(5)->post($url, $payload)->throw();
            }
        }
    }
}
