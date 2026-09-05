<?php

declare(strict_types=1);

return [
    'menu' => [
        'file' => 'File',
        'file_import' => 'Import file…',
        'file_scan_email' => 'Email inboxes…',
        'help' => 'Help',
        'help_github_repo' => 'GitHub repo',
        'help_report_issue' => 'Report an issue',
        'help_about' => 'About Beatrax',
        'developer_submenu' => 'Developer',
        'dev_open_console' => 'Open Dev Console',
        'dev_run_command' => 'Run a command…',
    ],

    'worker_alert' => [
        'body' => "Beatrax's background processing stopped unexpectedly. Imports and email scans are paused. Reopen the app to restart it.",
        'os_title' => 'Background work stopped',
    ],

    'notification' => [
        'hidden_details_body' => 'Open Beatrax to see the details.',
    ],
];
