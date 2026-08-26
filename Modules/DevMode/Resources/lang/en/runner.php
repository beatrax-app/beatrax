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
        'arg' => 'argument|arguments',
        'started' => 'Started :command (run :runId)',
        'run_expired' => 'Run record expired — cannot re-run.',
        'reran' => 'Re-ran :command (run :runId)',
    ],
];
