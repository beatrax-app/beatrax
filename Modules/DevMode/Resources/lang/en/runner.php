<?php

declare(strict_types=1);

return [
    'heading' => 'Artisan runner',
    'subtitle' => 'Run SAFE commands one-click; DESTRUCTIVE commands behind the triple-gate.',
    'run_a_command' => 'Run a command',
    'filter_aria' => 'Run filter',
    'filter' => [
        'all' => 'All',
        'running' => 'Running',
        'failed' => 'Failed',
        'destructive' => 'Destructive',
    ],
    'worker_running' => 'Queue worker: RUNNING',
    'worker_not_running' => 'Queue worker: NOT RUNNING',
    'no_runs' => 'No runs yet. Click "Run a command" or use the command palette (⌘K).',
    'no_runs_touch' => 'No runs yet. Tap "Run a command" or use the command palette (⌘K).',
    'recent_runs_aria' => 'Recent runs',
    'modal_heading' => 'Run a SAFE command',
    'modal_intro' => "Pick a SAFE-tier command to run immediately. DESTRUCTIVE commands are not listed here — use the timeline's Re-run affordance or the ⌘K palette.",
    'args_badge' => 'args',
    'args_badge_title' => 'Opens an arg form',

    'spawning_unavailable' => 'Artisan commands run in a separate process, and this platform will not let the app start one. Run them from the desktop app instead.',

    'status' => [
        'running' => 'Running',
        'done' => 'Done',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],
    'cancel' => 'Cancel',
    'rerun' => 'Re-run',
    'started' => 'Started :when',
    'exit' => 'exit',

    'toast' => [
        'unknown_command' => 'Unknown command: :command',
        'missing_args' => "Can't run :command — needs :noun: :list",
        'invalid_args' => "Can't run :command — :reason",
        'arg' => 'argument|arguments',
        'started' => 'Started :command (run :runId)',
        'run_expired' => 'Run record expired — cannot re-run.',
        'reran' => 'Re-ran :command (run :runId)',
        'rerun_forbidden' => 'That run belongs to another developer.',
    ],

    'command' => [
        'db_backup' => ['label' => 'Back up database', 'description' => 'Write a timestamped SQLite copy to the backups directory.'],
        'doctor' => ['label' => 'Run doctor', 'description' => 'Report installed PHP / Composer / SQLite versions and verify minimums.'],
        'failed_jobs' => ['label' => 'Prune failed jobs', 'description' => 'Prune resolved entries from the Laravel-managed failed_jobs table.'],
        'cache_clear' => ['label' => 'Clear cache', 'description' => 'Flush the application cache store.'],
        'route_list' => ['label' => 'List routes', 'description' => 'Print every registered HTTP route to stdout.'],
        'config_show' => ['label' => 'Show config', 'description' => 'Print the value at the given dotted config key.'],
        'view_clear' => ['label' => 'Clear view cache', 'description' => 'Flush the compiled Blade-view cache.'],
        'queue_retry' => ['label' => 'Retry failed jobs', 'description' => 'Retry one (by id) or every (blank id) failed job.'],
        'rederive_fingerprints' => ['label' => 'Rederive fingerprints', 'description' => 'Re-compute every transaction fingerprint using the current normalization version.'],
        'db_restore' => ['label' => 'Restore database', 'description' => 'Replace the current database with the given backup file.'],
        'regenerate_recovery_codes' => ['label' => 'Regenerate recovery codes', 'description' => 'Regenerate the 10 single-use recovery codes for a user.'],
        'grant_dev' => ['label' => 'Grant developer access', 'description' => 'Set is_developer=true for the given user.'],
        'install' => ['label' => 'Run install', 'description' => 'Idempotent first-run setup. Re-running on a configured install is destructive.'],
    ],

    'arg' => [
        'action' => ['label' => 'Action'],
        'config' => ['label' => 'Config key', 'help' => 'The config file or dotted key to print, e.g. `app` or `database.connections.sqlite`.', 'placeholder' => 'app.name'],
        'id' => ['label' => 'Job id', 'help' => 'Leave blank to retry every failed job; pass an id to retry a single entry.', 'placeholder' => 'all (or a specific id)'],
        'queue' => ['label' => 'Queue name', 'help' => 'Optional queue filter; defaults to all queues.', 'placeholder' => 'default'],
        'path' => ['label' => 'Backup file path', 'help' => 'Replaces the current database with the file at the given path.', 'placeholder' => '/path/to/backup.sqlite'],
        'username' => ['label' => 'Username', 'placeholder' => 'alice'],
    ],
];
