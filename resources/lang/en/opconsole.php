<?php

return [
    'clear_ok' => 'All caches cleared.',
    'clear_error' => 'Could not clear caches.',
    'optimize_ok' => 'Performance optimized: configuration, routes, and views cached.',
    'optimize_error' => 'Optimization failed. Review the output.',
    'migrate_ok' => 'Migrations completed.',
    'migrate_error' => 'Migrations failed. Review the output.',
    'down_ok' => 'The application is now in maintenance mode. Save the bypass URL from the output; it is shown only once.',
    'down_error' => 'Could not enable maintenance mode. Review the output.',
    'up_ok' => 'The application is back online.',
    'up_error' => 'Could not disable maintenance mode. Review the output.',
    'log_deleted' => 'Deleted log file :file.',
    'log_delete_latest_blocked' => 'Cannot delete the latest log file because it is still being written.',
    'log_delete_failed' => 'Could not delete log file :file. Check filesystem permissions.',
    'audit_unavailable' => 'The operation finished, but its audit record could not be saved. Verify database connectivity and run migrations.',
    'lock_busy' => 'Another operation is already running. Try again shortly.',
    'lock_unavailable' => 'Operation blocked: action lock store [:store] is unsafe or unavailable (:reason).',
];
