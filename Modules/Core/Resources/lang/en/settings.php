<?php

declare(strict_types=1);

return [
    'title' => 'Settings',
    'subtitle' => 'Preferences for how your finances appear in the app.',

    'appearance' => [
        'heading' => 'Appearance',
        'theme' => 'Theme',
        'theme_light' => 'Light',
        'theme_dark' => 'Dark',
        'theme_system' => 'System',
        'theme_help' => "System follows your operating system's light or dark setting.",
    ],

    'language' => [
        'heading' => 'Language',
        'label' => 'Display language',

        'system' => 'System',
        'help' => 'System follows your browser or operating system language, defaulting to English.',
    ],

    'currency_display' => [
        'heading' => 'Currency display',
        'label' => 'Default view on the transactions list',
        'eur_only' => 'EUR only',
        'original' => 'Original currency',
        'help' => 'You can still switch per page from the transactions list.',
    ],

    'base_currency' => [
        'heading' => 'Base reporting currency',
        'label' => 'Reporting currency',
        'help' => 'All totals and roll-ups convert to this currency. Each account still shows its own original currency alongside.',
    ],

    'exchange_rates' => [
        'heading' => 'Exchange rates',
        'fetch_online' => 'Fetch current rates online',
        'online_on' => 'Rates fetched from ECB daily. Only currency pair lookups — no personal data.',
        'last_updated' => 'Last updated: :date.',
        'online_off' => 'Bundled rates are used. No data leaves this device.',
        'fetch_aria' => 'Fetch current exchange rates online',
        'refreshing' => 'Refreshing…',
        'next_refresh' => 'Next auto-refresh: daily at 09:00',
        'refresh_now' => 'Refresh now',
    ],

    'period' => [
        'heading' => 'Period',
        'label' => 'Period starts on day',
        'help' => 'Numbered 1 to 28. Most users keep this on 1 (calendar month). Use 25 if your salary lands on the 25th and you think of "your month" as starting then.',
    ],

    'recurring' => [
        'heading' => 'Recurring detection',
        'window_label' => 'Detection window (months)',
        'window_help' => 'How many months of history to scan when clustering transactions into recurring patterns.',
        'income_label' => 'Income minimum (cents)',
        'income_help' => 'Incomes below this threshold are not auto-clustered. Stored in cents — 200000 means €2,000.00. Set to 0 to disable the threshold.',
    ],

    'drift' => [
        'heading' => 'Drift alerts',
        'label' => 'Default drift alert threshold',
        'help' => "Alerts fire when a recurring charge's latest amount differs from the prior amount by more than this percentage. Per-series overrides take precedence.",
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (default)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Save settings',
    'saved' => 'Saved.',

    'anomaly_heading' => 'Anomaly detection',
    'notifications_heading' => 'Notifications',

    'forecasting' => [
        'heading' => 'Forecasting',
        'intro' => "beatrax projects your balance forward from your accounts' current state. For accounts without statement balances (PayPal, legacy CSV imports), set the opening balance here so projections start from a known point.",
        'no_accounts' => 'No accounts yet — import a statement to add one.',
    ],

    'auto_import' => [
        'heading' => 'Auto-import',
        'label' => 'Auto-import from drop folder',

        'active_html' => 'Drop folder is active. beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> every 5 minutes for new files.',
        'inactive_html' => 'When on, beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> every 5 minutes for <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> and <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> files and imports them through the same matcher pipeline as the wizard. Processed files move to <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> so they\'re never imported twice.',
    ],

    'aliases' => [
        'heading' => 'Aliases',
        'intro' => "Review and edit the friendly names you've taught beatrax for cryptic statement descriptions.",
        'manage' => 'Manage aliases →',
    ],

    'tax_heading' => 'Tax',
    'shared_merchant_heading' => 'Shared merchant list',
    'data_backup_heading' => 'Data & backup',
    'install_heading' => 'Install',

    'about_updates' => [
        'heading' => 'About updates',
        'body' => "beatrax updates itself automatically once installed. After installing the very first version, future versions arrive via an in-app banner — you don't need to revisit GitHub. If a future update ever fails to apply, you can always re-download the latest installer manually from the releases page.",
        'open_releases' => 'Open releases page →',
    ],

    'first_run_tour' => [
        'heading' => 'First-run tour',
        'body' => 'Re-launch the setup wizard if you want to walk back through the introductory flow.',
        'run_again' => 'Run setup wizard again',
    ],

    'developer' => [
        'heading' => 'Developer',
        'label' => 'In-app Dev Console',
        'help' => 'Show the Dev Console at /dev. Resets the Advanced toggle on every login.',
        'aria' => 'Developer mode',
    ],

    'errors' => [
        'currency_required' => 'Please choose a currency.',
        'window_months' => 'Choose between 2 and 60 months.',
        'threshold' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
        'amount' => 'Enter an amount from €0 upward.',
        'period_day' => 'Choose a day from 1 to 28.',
        'currency_view' => 'Pick one of the available options.',
    ],
];
