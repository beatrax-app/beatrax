<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Welcome',
        'heading' => 'Welcome to beatrax',
        'subtitle' => 'Your local-only finance dashboard is ready. Create your first account to begin.',
        'get_started' => 'Get started',
    ],

    'setup' => [
        'page_title' => 'Setting up…',
        'pending_heading' => 'Setting up…',
        'pending_body' => 'beatrax is preparing your data. This only takes a moment.',
        'ready_heading' => 'Ready',
        'ready_body' => 'Setup complete. Continuing…',
    ],

    'staging' => [
        'page_title' => 'File received',
        'heading_prefix' => 'File received: ',
        'button_label' => 'Start import',
        'csv_subtitle' => 'A bank or PayPal export — start the import to preview and confirm.',
        'eml_subtitle' => 'An email receipt — start the import to attach it to its transaction.',
        'empty_heading' => "We couldn't open that file",
        'empty_body' => "beatrax couldn't read the file you opened. Try importing it from the Imports page instead.",
        'open_imports' => 'Open Imports',
    ],

    'close' => [
        'title' => 'Keep beatrax running?',
        'body' => 'Closing the window can either quit beatrax completely or keep it running quietly in the menu bar so scheduled email scans continue.',
        'button_quit' => 'Quit beatrax',
        'button_keep_in_tray' => 'Keep running in the tray',
        'checkbox_remember' => 'Remember my choice',
    ],
];
