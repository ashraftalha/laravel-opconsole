---
title: Routes & Endpoints
---

# Routes & Endpoints

With the default configuration (`route.prefix = 'admin/opconsole'`, `route.name_prefix = 'opconsole.'`), OpConsole registers these routes:

| Method | URI | Route name | Middleware |
|---|---|---|---|
| `GET` | `{prefix}/` | `opconsole.index` | `web`, `opconsole.authorize:view` |
| `GET` | `{prefix}/assets/{asset}` | `opconsole.asset` | `web`, `opconsole.authorize:view` |
| `GET` | `{prefix}/logs/{file}` | `opconsole.log` | `web`, `opconsole.authorize:download`, `throttle:30,1,opconsole-download` |
| `DELETE` | `{prefix}/logs/{file}` | `opconsole.log.delete` | `web`, `opconsole.authorize:delete`, `throttle:6,1,opconsole-delete` |
| `POST` | `{prefix}/failed-jobs/{id}/retry` | `opconsole.failed_jobs.retry` | `web`, `opconsole.authorize:run`, `throttle:20,1,opconsole-retry` |
| `POST` | `{prefix}/{action}` | `opconsole.run` | `web`, `opconsole.authorize:run`, `throttle:6,1,opconsole-run` |
| `GET` | `{prefix}/health` | `opconsole.health` | `throttle:{health_endpoint.rate_limit},1,opconsole-health` (no `web` group) |

`{prefix}` defaults to `admin/opconsole`; `{action}` is constrained to the keys of the allow-list (`ActionRunner::ACTIONS`); `{file}` is constrained to `[\w.-]+\.log`; `{id}` is constrained to `FailedJobs::ID_PATTERN`.

## Route registration rules

- Every route — including `health` — is registered under `route.prefix` and `route.domain`. Setting `enabled` to `false` registers none of them.
- `route.middleware` is merged with `'web'` unconditionally: `array_values(array_unique(['web', ...$extraMiddleware]))`. Overriding `route.middleware` can never strip session state or CSRF protection from the `POST`/`DELETE` routes, even if you configure `route.middleware => []`.
- The health endpoint is **deliberately outside the `web` middleware group** — machine clients get no session, no cookies, and no CSRF; the bearer token is the only credential. It's also the only route not gated by `opconsole.authorize`, since it has no concept of an authenticated user.
- Do not add Laravel's `auth` middleware alias to `route.middleware`. It calls `route('login')` unconditionally and throws (`500`) on a host with no route named `login`. `AuthorizeOpConsole` performs the guest check itself and handles this gracefully — see [Authentication & Authorization](/guide/authentication).

## The asset route as a fallback

`GET {prefix}/assets/{asset}` (constrained to `opconsole.css`, `opconsole.js`, `opconsole.svg`) serves the package's CSS/JS/favicon automatically if you haven't run `vendor:publish --tag=opconsole-assets`. It's gated by the view Gate like every other read-only route. Publishing assets to `public/vendor/opconsole/` is still recommended for production, since it serves them directly from your web server instead of through PHP.

## Rate limits

| Route | Limit |
|---|---|
| Log download | 30 per minute |
| Log delete | 6 per minute |
| Failed-job retry | 20 per minute |
| Artisan action run | 6 per minute |
| Health endpoint | `health_endpoint.rate_limit` per minute (default 60) |

These are independent, named rate-limit buckets (`opconsole-download`, `opconsole-delete`, `opconsole-retry`, `opconsole-run`, `opconsole-health`) — exhausting one never starves another.
