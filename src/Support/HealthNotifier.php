<?php

namespace AshrafTalha\OpConsole\Support;

use AshrafTalha\OpConsole\Notifications\HealthTransitionNotification;
use AshrafTalha\OpConsole\Notifications\HealthWebhookChannel;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Persists only the last status and cooldown marker; called explicitly by scheduled monitoring. */
class HealthNotifier
{
    public function evaluate(array $report): void
    {
        $config = config('opconsole.notifications', []);
        if (! is_array($config) || ! ($config['enabled'] ?? false)) {
            return;
        }
        $store = $config['state_store'] ?: config('opconsole.concurrency.lock_store', 'opconsole');
        $cache = null;
        $lock = null;
        try {
            $cache = Cache::store($store);
            $backend = $cache->getStore();
            if (! $backend instanceof LockProvider) {
                Log::warning('OpConsole: health notifications are enabled but the state store does not support locks; nothing was sent', ['store' => (string) $store]);

                return;
            }
            // Covers sequential delivery to several destinations (each bounded by HealthWebhookChannel timeouts).
            $lock = $backend->lock('opconsole:health-notifications', 120);
            if (! $lock->get()) {
                return;
            }
            $key = 'opconsole:health-notification-state';
            $previous = $cache->get($key, ['status' => 'healthy', 'sent_at' => 0, 'event' => null]);
            $current = (string) $report['status'];
            $rank = ['healthy' => 0, 'warning' => 1, 'critical' => 2];
            $oldRank = $rank[$previous['status'] ?? 'healthy'] ?? 0;
            $newRank = $rank[$current] ?? 0;
            $event = $newRank === 0 && $oldRank > 0 ? 'recovery'
                : ($oldRank === 0 && $newRank > 0 ? 'unhealthy'
                    : ($newRank > $oldRank ? 'worsening' : null));
            $now = time();
            $cooldown = max(0, (int) ($config['cooldown_seconds'] ?? 3600));
            $sameEvent = ($previous['event'] ?? null) === $event && ($previous['status'] ?? null) === $current;
            if ($event !== null && ! ($sameEvent && $now - (int) ($previous['sent_at'] ?? 0) < $cooldown)) {
                $channels = is_array($config['channels'] ?? null) ? $config['channels'] : [];
                $notification = new HealthTransitionNotification($event, $report);
                $attempts = [];
                if (! empty($channels['email'])) {
                    $attempts['mail'] = fn () => Notification::sendNow(Notification::route('mail', $channels['email']), $notification, ['mail']);
                }
                if (! empty($channels['webhooks']) || ! empty($channels['slack'])) {
                    $attempts['webhook'] = fn () => Notification::sendNow(Notification::route(HealthWebhookChannel::class, $channels), $notification, [HealthWebhookChannel::class]);
                }

                // Channels are isolated: a failing mailer must not suppress the webhooks, or vice versa.
                $delivered = 0;
                foreach ($attempts as $channel => $send) {
                    try {
                        $send();
                        $delivered++;
                    } catch (Throwable $e) {
                        // Class only: transport messages can embed the destination URL, and webhook URLs are credentials.
                        Log::warning('OpConsole: health notification channel failed', ['channel' => $channel, 'error' => class_basename($e)]);
                    }
                }

                // If every channel failed nobody was told: keep the old status so the next run sees the transition again.
                if ($delivered === 0 && $attempts !== []) {
                    return;
                }
                $previous['event'] = $event;
                $previous['sent_at'] = $now;
            }
            $previous['status'] = $current;
            $cache->put($key, $previous, max(86400, $cooldown * 2));
        } catch (Throwable $e) {
            Log::warning('OpConsole: health notification could not be evaluated', ['error' => class_basename($e)]);
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
            }
        }
    }
}
