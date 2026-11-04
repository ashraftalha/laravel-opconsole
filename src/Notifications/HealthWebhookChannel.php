<?php

namespace AshrafTalha\OpConsole\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronous webhook delivery keeps transport failures local to the monitoring command
 * (no dependency on the queue it may be reporting as down). Each destination is isolated;
 * the channel only fails when every configured destination failed.
 */
class HealthWebhookChannel
{
    public function send(object $notifiable, HealthTransitionNotification $notification): void
    {
        $routes = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor(self::class)
            : [];
        $routes = is_array($routes) ? $routes : [];
        $attempted = 0;
        $delivered = 0;

        foreach (['webhooks', 'slack'] as $type) {
            foreach (array_values((array) ($routes[$type] ?? [])) as $index => $url) {
                if (! self::validUrl($url)) {
                    Log::warning('OpConsole: skipped invalid health notification webhook URL', ['type' => $type, 'index' => $index]);

                    continue;
                }
                $payload = $notification->payload();
                if ($type === 'slack') {
                    $payload = ['text' => 'OpConsole health '.$payload['event'].': '.$payload['status'], 'attachments' => [['text' => json_encode($payload, JSON_UNESCAPED_SLASHES)]]];
                }
                $attempted++;

                try {
                    // No redirects: the configured destination is the only host that receives the payload.
                    $response = Http::connectTimeout(3)->timeout(5)->withoutRedirecting()->post($url, $payload);
                    if ($response->successful()) {
                        $delivered++;

                        continue;
                    }
                    $error = 'HTTP '.$response->status();
                } catch (Throwable $e) {
                    // Never log the message: it embeds the URL, and webhook URLs are credentials.
                    $error = class_basename($e);
                }
                Log::warning('OpConsole: health notification webhook failed', ['type' => $type, 'index' => $index, 'error' => $error]);
            }
        }

        if ($attempted > 0 && $delivered === 0) {
            throw new \RuntimeException("All {$attempted} health notification webhook(s) failed.");
        }
    }

    private static function validUrl(mixed $url): bool
    {
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true);
    }
}
