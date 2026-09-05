<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Type to search views, commands, and actions. Press Esc to close.',
    'search_aria' => 'Type to search views, commands, and actions',
    'dialog_aria' => 'Command palette',
    'token_suggest_aria' => 'Token suggestions',
    'rail_view' => 'View',
    'rail_dev' => 'Dev',
    'rail_action' => 'Action',
    'rail_recent' => 'Recent',
    'no_recent' => 'No recent picks yet.',
    'section_transactions' => 'Transactions',
    'section_counterparties' => 'Counterparties',
    'section_categories' => 'Categories',
    'section_goals_recurring' => 'Goals & Recurring',
    'no_name' => '(no name)',
    'see_all' => 'See :count result →|See all :count results →',
    'no_transactions' => 'No transactions match ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'counterparty',
    'source_category' => 'category',
    'results_aria' => 'Results',
    'no_results' => 'No results.',
    'foot_navigate' => 'navigate',
    'foot_select' => 'select',
    'foot_close' => 'close',
    'close_aria' => 'Close search',
    'close_caption' => 'Close',
    'foot_try' => 'Try',
    'results' => ':count result|:count results',

    'action' => [
        'run_import' => ['label' => 'Run import', 'hint' => 'Open the import wizard'],
        'scan_email' => ['label' => 'Open inboxes', 'hint' => 'Your connected mailboxes'],
        'open_profile' => ['label' => 'Open profile', 'hint' => 'Settings — account and preferences'],
        'toggle_theme' => ['label' => 'Open appearance settings', 'hint' => 'Light, dark or system'],
    ],

    'run_command' => 'Run :command',

    'nav' => [
        'overview' => ['label' => 'Dev overview', 'hint' => 'System tiles + recent runs'],
        'artisan' => ['label' => 'Artisan runner', 'hint' => 'Run whitelisted commands'],
        'audit' => ['label' => 'Dev audit log', 'hint' => 'Your dev-mode actions'],
        'logs' => ['label' => 'Log tailer', 'hint' => 'Live laravel-*.log stream'],
        'queue' => ['label' => 'Queue inspector', 'hint' => 'Pending / failed / batches'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'System probes'],
        'sql' => ['label' => 'SQL panel', 'hint' => 'SELECT-only browser'],
        'system' => ['label' => 'System snapshot', 'hint' => 'Env + paths + config'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Embedded queue dashboard'],
        'sync_health' => ['label' => 'Sync health', 'hint' => 'Quarantined / skipped merge ops'],
    ],
];
