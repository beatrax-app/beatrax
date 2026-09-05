<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Display',
        'money' => 'Money',
        'insights' => 'Insights & alerts',
        'security' => 'Security & devices',
        'data' => 'Imports & data',
        'app' => 'App',
    ],

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
        'apply' => 'Apply',
        'heading' => 'Language',
        'label' => 'Display language',

        'system' => 'System',
        'help' => 'Changes the words on screen, and how amounts are written. System follows your browser or operating system language, defaulting to English.',
    ],

    'country' => [
        'heading' => 'Country',
        'label' => 'Your country',
        'help' => "Decides which country's tax rules, government bodies and bank fees the app recognises. It does not change the language or how amounts are written.",
        'choose' => 'Choose a country…',
        'switch_note' => 'Switching adds new categories — existing tags are never changed.',

        'wording_note' => 'Tax category names are shown in your language; the :country tax return itself uses its own wording.',

        'countries' => [
            'at' => 'Austria',
            'be' => 'Belgium',
            'bg' => 'Bulgaria',
            'ca' => 'Canada',
            'ch' => 'Switzerland',
            'cy' => 'Cyprus',
            'cz' => 'Czechia',
            'de' => 'Germany',
            'dk' => 'Denmark',
            'ee' => 'Estonia',
            'es' => 'Spain',
            'fi' => 'Finland',
            'fr' => 'France',
            'gb' => 'United Kingdom',
            'gr' => 'Greece',
            'hr' => 'Croatia',
            'hu' => 'Hungary',
            'ie' => 'Ireland',
            'is' => 'Iceland',
            'it' => 'Italy',
            'lt' => 'Lithuania',
            'lu' => 'Luxembourg',
            'lv' => 'Latvia',
            'mt' => 'Malta',
            'nl' => 'Netherlands',
            'no' => 'Norway',
            'pl' => 'Poland',
            'pt' => 'Portugal',
            'ro' => 'Romania',
            'se' => 'Sweden',
            'si' => 'Slovenia',
            'sk' => 'Slovakia',
            'us' => 'United States',
        ],
    ],

    'currency_display' => [
        'heading' => 'Amount display',
        'label' => 'Default view for amounts',
        'eur_only' => 'Settled amount',
        'original' => 'Original amount',
        'help' => 'Applies to the transactions list and the totals on the dashboard. You can still switch per page, but only from the transactions list.',
    ],

    'base_currency' => [
        'heading' => 'Base reporting currency',
        'label' => 'Reporting currency',
        'help' => 'All totals and roll-ups convert to this currency. Each account still shows its own original currency alongside.',
    ],

    'exchange_rates' => [
        'heading' => 'Exchange rates',
        'fetch_online' => 'Fetch current rates online',
        'online_on' => 'Rates fetched daily from the ECB, falling back to Frankfurter if the ECB is unreachable. Only currency pair lookups — no personal data.',
        'last_updated' => 'Last updated: :date.',
        'online_off' => 'The rates already on this device stay in use, with the bundled snapshot as the fallback. No data leaves this device.',
        'fetch_aria' => 'Fetch current exchange rates online',
        'refreshing' => 'Refreshing…',
        'next_refresh' => 'Auto-refresh: once a day',
        'refresh_gave_up' => 'Could not refresh the rates. The rates already on this device are still in use.',
        'refresh_now' => 'Refresh now',
    ],

    'period' => [
        'heading' => 'Period',
        'label' => 'Period starts on day',
        'help' => 'Numbered 1 to 28. Most users keep this on 1 (calendar month). Use 25 if your salary lands on the 25th and you think of "your month" as starting then.',

        'move_confirm' => 'Starting the period on day :day re-files every envelope amount, adding two together wherever two months fold into one. Moving the day back does not split them again.',
        'move_cancel' => 'Cancel',
        'move_apply' => 'Apply',
    ],

    'recurring' => [
        'heading' => 'Recurring detection',
        'window_label' => 'Detection window (months)',
        'window_help' => 'How many months of history to scan when clustering transactions into recurring patterns.',
        'income_label' => 'Income minimum (minor units)',
        'income_help' => 'Incomes below this threshold are not auto-clustered. Stored in minor units — :minor means :example. Set to 0 to disable the threshold.',
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
        'intro' => "Beatrax projects your balance forward from your accounts' current state. For accounts without statement balances (PayPal, legacy CSV imports), set the opening balance here so projections start from a known point.",
        'no_accounts' => 'No accounts yet — import a statement to add one.',
    ],

    'auto_import' => [
        'heading' => 'Auto-import',
        'label' => 'Auto-import from drop folder',

        'active_html' => 'Drop folder is active. Beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> every 5 minutes for new files.',
        'inactive_html' => 'When on, Beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> every 5 minutes for <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> and <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> files and imports them through the same matcher pipeline as the wizard. Processed files move to <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> so they\'re never imported twice.',
        'active_phone_html' => 'Drop folder is active. Beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> for new files in the background. Your phone decides when a background scan runs, so it can be minutes or hours.',
        'inactive_phone_html' => 'When on, Beatrax scans <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> in the background for <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> and <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> files and imports them through the same matcher pipeline as the wizard. Your phone decides when a background scan runs, so it can be minutes or hours. Processed files move to <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> so they\'re never imported twice.',
    ],

    'aliases' => [
        'heading' => 'Aliases',
        'intro' => "Review and edit the friendly names you've taught Beatrax for cryptic statement descriptions.",
        'manage' => 'Manage aliases →',
    ],

    'tax_heading' => 'Tax',
    'data_backup_heading' => 'Data & backup',

    'about_updates' => [
        'heading' => 'About updates',
        'body' => "Beatrax updates itself automatically once installed. After installing the very first version, future versions arrive via an in-app banner — you don't need to revisit GitHub. If a future update ever fails to apply, you can always re-download the latest installer manually from the releases page.",
        'body_phone' => 'Beatrax does not update itself here. New versions of the phone app arrive through the App Store or Google Play, the same way your other apps do. The releases page lists what changed in each one.',
        'check_label' => 'Check for updates automatically',
        'check_on' => 'Beatrax asks the release feed whether a newer signed version exists. Nothing is downloaded until you choose to install it.',
        'check_off' => 'No update check is made and nothing leaves this device. New versions are found by opening the releases page yourself.',
        'open_releases' => 'Open releases page →',
    ],

    'privacy' => [
        'heading' => 'Privacy policy',
        'body' => 'Beatrax keeps your finances on your own devices. The policy explains what that means, what the optional online features send, and how to remove your data.',
        'open' => 'Read the privacy policy →',
        'url_hint' => 'If the link does not open, visit:',
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
        'period_move_failed' => 'The budget month could not be moved, so it was left where it was.',
        'currency_required' => 'Please choose a currency.',
        'window_months' => 'Choose between 2 and 60 months.',
        'threshold' => 'Choose a threshold from 1%, 2%, 5%, 10%, 25%, or 50%.',
        'amount' => 'Enter an amount from :zero upward.',
        'period_day' => 'Choose a day from 1 to 28.',
        'currency_view' => 'Pick one of the available options.',
    ],
];
