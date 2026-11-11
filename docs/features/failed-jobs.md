---
title: Failed Jobs & Retry
---

# Failed Jobs & Retry

[View failed jobs and retries screenshot](/screenshots/jobs-light.png) · [Browse all feature screenshots](/guide/introduction#feature-screenshot-directory)

## What it does

The **Failed jobs** tab lists the newest failed queue jobs — name, connection, queue, failure time, and the first line of the sanitized exception. Job **payloads are never shown**. Each row has a **Retry** button that runs `queue:retry {id}`.

<figure class="oc-shot">
  <img src="/screenshots/jobs-light.png" alt="OpConsole Failed jobs tab: failed queue jobs with their queue, failure time, error and a Retry button" width="1920" height="1245" loading="lazy">
  <figcaption>The Failed jobs tab: each job with its queue, failure time and error (never the payload), and a Retry button.</figcaption>
</figure>

## Why it is useful

Seeing what failed, and retrying one specific job, previously meant SSH and `php artisan queue:failed` / `queue:retry`. This surfaces the same information and the same action in the browser, behind the same Gates as everything else.

## How to configure it

```dotenv
OPCONSOLE_FAILED_JOBS=true
OPCONSOLE_FAILED_JOBS_LIMIT=50
OPCONSOLE_FAILED_JOBS_RETRY=true
```

| Key | Default | Description |
|---|---|---|
| `failed_jobs.enabled` | `true` | Shows the tab. When `false`, it's hidden and retry requests return `403`. |
| `failed_jobs.limit` | `50` | How many jobs to list (1–500). Database providers are queried with this limit, so a large store is never loaded in full. |
| `failed_jobs.allow_retry` | `true` | Shows the Retry button. |

The list reads Laravel's configured failed-job store (`queue.failed.driver`). With the `null` driver, the tab explains that failed jobs aren't being stored at all.

## How to use it

Open the **Failed jobs** tab — its badge shows the current count. Filter the list, then press **Retry** on a specific job. The job re-runs with its original payload via `queue:retry {id}`.

## Security considerations

- Retrying needs the run Gate and, when configured, the Gate mapped to `queue_retry` in `authorization.action_gates` — map it to a narrower Gate if you want to separate "can view failures" from "can re-trigger a job with side effects."
- Retry requests are rate limited to 20 per minute, require confirmation in the UI, and are recorded in the audit history as `queue.retry`.
- Under `audit.policy=required`, a retry whose audit row can't be written is refused rather than running unaudited.
- The job's ID must match `FailedJobs::ID_PATTERN` and exist in the failed-job store — there's no way to pass an arbitrary identifier through to the underlying command.

## Limitations

- Retrying re-runs the job with its **original** payload — if the underlying bug wasn't fixed, it will likely fail again. This is standard `queue:retry` behavior, not a special safety net.
- There's no bulk retry; the UI is intentionally one job at a time.
- The tab reflects Laravel's own failed-job store faithfully — if jobs aren't landing there in the first place (for example a custom `failed()` handler that swallows them), OpConsole has nothing to show.

## Troubleshooting

- **Retry button is disabled:** the run Gate or the mapped `queue_retry` Gate denies access, or `failed_jobs.allow_retry` is `false`. See the [permission matrix](/guide/authentication#effective-permission-matrix).
- **Tab explains "failed jobs are not being stored":** your `queue.failed.driver` is `null`. Configure a `database` or other supported failed-job driver in Laravel to start recording failures.
