---
title: Environment Variables
---

# Environment Variables

Every environment variable OpConsole reads, with its config key and default. For full explanations, see the [Configuration Reference](/reference/configuration).

## Core

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_ENABLED` | `enabled` | `true` |
| `OPCONSOLE_ROUTE_PREFIX` | `route.prefix` | `admin/opconsole` |
| `OPCONSOLE_THEME` | `theme` | `null` (`auto` for standalone layout, `light` when embedded) |
| `OPCONSOLE_THEME_TOGGLE` | `theme_toggle` | `true` |
| `OPCONSOLE_WEB_FONTS` | `web_fonts` | `false` |
| `OPCONSOLE_TABLE` | `table` | `opconsole_runs` |
| `OPCONSOLE_CONNECTION` | `connection` | `null` |
| `OPCONSOLE_HISTORY_LIMIT` | `history_limit` | `15` |

## Concurrency

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_LOCK_STORE` | `concurrency.lock_store` | `opconsole` |
| `OPCONSOLE_LOCK_SECONDS` | `concurrency.lock_seconds` | `1800` |
| `OPCONSOLE_MAX_RUNTIME_SECONDS` | `concurrency.max_runtime_seconds` | `1500` |

## Audit

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_AUDIT_POLICY` | `audit.policy` | `best_effort` |

## Heartbeats

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_HEARTBEAT_STORE` | `heartbeats.store` | `null` |
| `OPCONSOLE_HEARTBEAT_SCHEDULER` | `heartbeats.scheduler` | `true` |
| `OPCONSOLE_HEARTBEAT_SCHEDULER_MAX_AGE` | `heartbeats.scheduler_max_age` | `120` |
| `OPCONSOLE_HEARTBEAT_QUEUE` | `heartbeats.queue` | `true` |
| `OPCONSOLE_HEARTBEAT_QUEUE_MAX_AGE` | `heartbeats.queue_max_age` | `300` |
| `OPCONSOLE_HEARTBEAT_QUEUE_CONNECTION` | `heartbeats.queue_connection` | `null` |
| `OPCONSOLE_HEARTBEAT_QUEUE_NAME` | `heartbeats.queue_name` | `null` |

## Disk

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_DISK_MIN_FREE_PERCENT` | `disk.min_free_percent` | `10` |

## Health endpoint

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_HEALTH_ENDPOINT` | `health_endpoint.enabled` | `false` |
| `OPCONSOLE_HEALTH_TOKEN` | `health_endpoint.token` | `null` |
| `OPCONSOLE_HEALTH_RATE_LIMIT` | `health_endpoint.rate_limit` | `60` |

## Actions

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_ACTION_STORAGE_LINK` | `actions.storage_link` | `false` |
| `OPCONSOLE_ACTION_SECURITY_SCAN` | `actions.security_scan` | `true` |
| `OPCONSOLE_ACTION_OCTANE_RELOAD` | `actions.octane_reload` | `true` |

## Octane

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_OCTANE_CHECKS` | `octane.checks` | `auto` |
| `OPCONSOLE_OCTANE_EXPECTED` | `octane.expected_in_production` | `false` |
| `OPCONSOLE_OCTANE_ALLOW_ACTIONS` | `octane.allow_actions_in_swoole` | `false` |

## Maintenance

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_MAINTENANCE_ALLOW_CONSOLE` | `maintenance.allow_console` | `true` |

## Failed jobs

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_FAILED_JOBS` | `failed_jobs.enabled` | `true` |
| `OPCONSOLE_FAILED_JOBS_LIMIT` | `failed_jobs.limit` | `50` |
| `OPCONSOLE_FAILED_JOBS_RETRY` | `failed_jobs.allow_retry` | `true` |

## Security audit

| Variable | Config key | Default |
|---|---|---|
| `OPCONSOLE_COMPOSER` | `security.composer` | `composer` |
| `OPCONSOLE_SECURITY_TIMEOUT` | `security.timeout` | `120` |
| `OPCONSOLE_SECURITY_MAX_AGE` | `security.max_age_seconds` | `604800` |
| `OPCONSOLE_SECURITY_INCLUDE_DEV` | `security.include_dev` | `false` |
| `OPCONSOLE_SECURITY_STORE` | `security.store` | `opconsole` |
| `OPCONSOLE_SECURITY_SCHEDULE` | `security.schedule` | `false` |
| `OPCONSOLE_SECURITY_TRUSTED_PROXY_HTTPS` | `security.trusted_proxy_https` | `false` |

Variables without an `env()` call in `config/opconsole.php` (route name prefix, domain, Gate names, `authorization.action_gates`, `checks`, `health.*`, `notifications.*`, `layout`, `back_url`/`back_label`) are configured directly in the array — there is no corresponding environment variable for them. See the [Configuration Reference](/reference/configuration) for those.
