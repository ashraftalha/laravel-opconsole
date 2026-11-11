---
title: Log Viewer & Log Management
---

# Log Viewer & Log Management

[View log viewer screenshot](/screenshots/logs-light.png) · [Browse all feature screenshots](/guide/introduction#feature-screenshot-directory)

## What it does

The **Logs** tab lists readable log files, an entries-per-hour chart for the last 24 hours, level and environment filters, full-text search with highlighting, repeat counts for duplicate consecutive entries, and stack traces with your application's own frames highlighted and vendor frames dimmed.

<figure class="oc-shot">
  <img src="/screenshots/logs-light.png" alt="OpConsole Logs tab: log files, an entries-per-hour chart, level filters, search and log entries with repeat counts" width="1920" height="1245" loading="lazy">
  <figcaption>The Logs tab: file list, entries-per-hour chart, level filters, search, and repeat counts.</figcaption>
</figure>

## Why it is useful

Most production debugging starts with "what's actually in the log right now" — this gives you that without a terminal, with the noise (repeated identical entries, vendor-frame clutter) reduced.

## How to configure it

```php
// config/opconsole.php
'logs' => [
    'path' => storage_path('logs'),
    'allow_download' => true,
    'allow_delete' => true,
],
```

| Key | Default | Description |
|---|---|---|
| `logs.path` | `storage_path('logs')` | Directory scanned for readable `^[\w.-]+\.log$` files. |
| `logs.allow_download` | `true` | Hides download buttons and returns `403` from the download route when `false`. |
| `logs.allow_delete` | `true` | Hides delete buttons and returns `403` from the delete route when `false`. |

## How to use it

- Pick a file from the sidebar; filter by level or environment; search with highlighting.
- Use <kbd>j</kbd> / <kbd>k</kbd> to move between entries.
- **Download** a full raw file, or **Delete** an older, rotated one.

Routes:

| Method | URI | Gate |
|---|---|---|
| `GET` | `{prefix}/logs/{file}` | `downloadOpConsoleLogs` |
| `DELETE` | `{prefix}/logs/{file}` | `deleteOpConsoleLogs` |

## Security considerations

- **Logs are not redacted.** OpConsole does not scrub raw log lines rendered in the viewer or in downloaded files, because redacting arbitrary stack traces and payloads would also destroy diagnostic evidence. Treat `viewOpConsole` and `downloadOpConsoleLogs` as high-trust administrative permissions.
- Filenames are constrained to `^[\w.-]+\.log$`, resolved with `realpath()`, and checked for containment inside `logs.path` — path traversal and external symlinks are rejected. An internal symlink alias can't be used to bypass "newest file" delete protection, and deleting a symlink removes only the link, never its target.
- **The newest valid log file can never be deleted** (directly, or via a symlink resolving to it), so you can't accidentally remove the file currently being written.
- Downloads are rate limited to 30/minute; deletions to 6/minute.
- For high-compliance environments, consider the [Read-only diagnostic preset](/reference/configuration#_2-read-only-diagnostic-preset): set `allow_download` and `allow_delete` to `false`, and keep `APP_DEBUG=false` / `LOG_LEVEL=warning` or higher in production.

## Limitations

- Only the newest ~2 MB of a file is read for the in-browser tail view (to bound memory use on large logs); downloading gets the full file.
- Log viewing works on whatever your `logs.path` directory actually contains — it doesn't aggregate logs across multiple servers.

## Troubleshooting

- **No files listed:** confirm `logs.path` points at a real, readable directory and that filenames match `^[\w.-]+\.log$` (Laravel's default daily/rotating log names match this).
- **Download/delete buttons missing:** check `logs.allow_download` / `logs.allow_delete`, and that your user holds `downloadOpConsoleLogs` / `deleteOpConsoleLogs`.
