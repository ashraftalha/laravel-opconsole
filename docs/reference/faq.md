---
title: FAQ
description: "Answers to common Laravel OpConsole questions about access, Gates, production use, operations, logs and the security audit."
---

# FAQ

## Does OpConsole work without any configuration?

Yes, in the `local` environment — the fail-closed default allows access there automatically. Everywhere else, you must [define the Gates](/guide/authentication) yourself.

## Can I add my own Artisan command to the Operations tab?

No. The allow-list (`ActionRunner::ACTIONS`) is a closed constant — you can disable existing entries, but you cannot add new commands. See [Operational Safety](/advanced/operational-safety) for why this boundary exists.

## Does OpConsole read or expose my `.env` file?

No. It's an explicit non-feature — see [Explicit Non-Features](/reference/security#explicit-non-features).

## Does the log viewer redact sensitive data?

No, by design — redacting arbitrary stack traces and payloads would also destroy diagnostic evidence. Treat `viewOpConsole` and `downloadOpConsoleLogs` as high-trust permissions. See [Log Viewer](/features/log-viewer#security-considerations).

## Does OpConsole make any requests to third-party CDNs?

No, by default. The one opt-in exception is `web_fonts`, which loads IBM Plex from Google Fonts if you explicitly enable it.

## Will OpConsole work behind a strict Content-Security-Policy?

Yes — it emits zero inline `<script>` blocks and zero inline `style=` attributes.

## Does OpConsole support multiple servers / load balancing?

Yes, but the default action lock store only coordinates a single server. Point `OPCONSOLE_LOCK_STORE` at a shared Redis or database store for multi-server deployments — see [Permissions & Security Policies](/advanced/permissions-security-policies).

## What happens if I don't have `pcntl` available?

Timer-bound Artisan actions (clear caches, optimize, migrate, maintenance mode, storage link) are refused when triggered from the browser; the self-bounded security scan and Octane reload remain available when enabled and their prerequisites are met. Run the disabled operations from the CLI instead, or enable `pcntl` for your web SAPI. See [Execution environments](/reference/configuration#execution-environments).

## Can support staff clear caches without being able to run migrations?

Yes — map `clear` and `migrate` to separate Gates in `authorization.action_gates`. See [Authentication & Authorization](/guide/authentication#per-operation-permissions).

## Does retrying a failed job run it with new data?

No — `queue:retry {id}` re-runs the job with its **original** payload. If the underlying bug caused the failure, it will likely fail again until that's fixed.

## Is there a way to roll back a migration from the dashboard?

No. `migrate` only ever runs forward (`migrate --force --isolated`); there is no rollback or reset action in the allow-list.

## Does OpConsole support Laravel Octane?

Yes, automatically when Octane is installed — see [Octane & Swoole Monitoring](/features/octane-swoole). Nothing changes for applications without Octane.

## Why are timed actions refused inside a Swoole worker?

Their runtime budget is enforced with a `SIGALRM` signal, which inside a Swoole worker would interrupt the worker process itself rather than an isolated subprocess. Set `OPCONSOLE_OCTANE_ALLOW_ACTIONS=true` to accept that risk, or run them from the CLI instead.

## Where do I report a security vulnerability?

Privately — open a private security advisory on the GitHub repository, or contact the maintainer directly. Do not open a public issue for an exploitable vulnerability. See [Security Model](/reference/security).

## Is OpConsole officially affiliated with Laravel?

No. It's an independent, MIT-licensed package by Ashraf Talha.
