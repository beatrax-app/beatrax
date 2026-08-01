<?php

declare(strict_types=1);

return [
    'banner_aria' => 'System alerts',

    'actions' => [
        'install_next_launch' => 'Install on next launch',
        'install_next_launch_aria' => 'Install on next launch — marks system alert #:id as resolved',
        'skip_version' => 'Skip this version',
        'release_notes' => 'Release notes →',
        'update_now' => 'Update now',
        'update_now_aria' => 'Update now — marks system alert #:id as resolved',
        'remind_later' => 'Remind me later',
        'mark_resolved' => 'Mark as resolved',
        'mark_resolved_aria' => 'Mark as resolved — system alert #:id',
    ],

    'messages' => [
        'update_available' => 'Update available — beatrax :version is ready. It will install on next launch.',
        'update_stale' => "You're on version :current — version :latest has been available for 30 days. Update now.",
        'update_critical' => 'Critical update available — version :version fixes :summary. Install as soon as possible.',
        'backup_corrupt_with_path' => 'The backup written at :timestamp failed integrity check. Inspect :path. Resolve before relying on backups.',
        'backup_corrupt_no_path' => 'The backup attempted at :timestamp aborted before any file was produced — source DB failed integrity check. Resolve before relying on backups.',

        'backup_overdue' => 'The most recent verified backup is :hoursh old. Run <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> or wait for the 03:00 scheduled run.',
        'wal_mode_missing' => 'SQLite is not in WAL mode (currently :mode). Concurrent writes may stall. Run <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> for guidance.',
        'synchronous_misconfigured' => 'SQLite synchronous level is :level (expected NORMAL/1). Durability semantics may differ from config. Run <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code> for guidance.',
        'reconnect_link' => 'Reconnect →',
    ],
];
