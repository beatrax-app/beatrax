<?php

declare(strict_types=1);

return [
    'menu' => [
        'file_import' => 'Import file…',
        'file_scan_email' => 'Scan email now',
        'help_github_repo' => 'GitHub repo',
        'help_report_issue' => 'Report an issue',
        'help_about' => 'About beatrax',
        'developer_submenu' => 'Developer',
        'dev_open_console' => 'Open Dev Console',
        'dev_run_command' => '⌘K Run a command',
    ],

    'worker_alert' => [
        'body' => "beatrax's background processing stopped unexpectedly. Imports and email scans are paused. Reopen the app to restart it.",
        'os_title' => 'Background work stopped',
    ],

    'notification' => [
        'hidden_details_body' => 'Open beatrax to see the details.',
    ],
];
