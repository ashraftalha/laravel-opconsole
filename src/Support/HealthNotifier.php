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
                return;
            }
            $lock = $backend->lock('opconsole:health-notifications', 30);
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
                if (! empty($channels['email'])) {
                    Notification::route('mail', $channels['email'])->notify($notification);
                }
                if (! empty($channels['webhooks']) || ! empty($channels['slack'])) {
                    Notification::route(HealthWebhookChannel::class, $channels)
                        ->notify($notification);
                }
                $previous['event'] = $event;
                $previous['sent_at'] = $now;
            }
            $previous['status'] = $current;
            $cache->put($key, $previous, max(86400, $cooldown * 2));
        } catch (Throwable $e) {
            Log::warning('OpConsole: health notification could not be delivered', ['error' => ActionRunner::sanitizeText($e->getMessage())]);
        } finally {
            try {
                $lock?->release();
            } catch (Throwable) {
            }
        }
    }
}
