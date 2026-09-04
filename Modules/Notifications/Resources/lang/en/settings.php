<?php

declare(strict_types=1);

return [
    'what_heading' => 'What to notify me about',
    'background_note' => 'Beatrax prepares these while it is open. A scheduled background run cannot — the app lock holds the only key — so anything due is picked up as you carry on using the app.',
    'background_note_phone' => 'Beatrax prepares these while it is open. In the background it cannot — the app lock holds the only key — so anything due arrives the next time you open the app.',

    'reminders' => [
        'label' => 'Payment reminders',
        'help' => 'Get a heads-up before a recurring payment is due.',
    ],

    'lead_days' => [
        'label' => 'Remind me ___ days before',
        'help' => 'How many days ahead of the due date the reminder fires. 1–30 days.',
    ],

    'budget_nudges' => [
        'label' => 'Budget nudges',
        'help' => 'Get told when a category budget is nearly spent.',
    ],

    'digest' => [
        'label' => 'Your position',
        'help' => 'How often you get a summary of where things stand this period.',
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'off' => 'Off',
    ],

    'savings' => [
        'label' => 'Savings-opportunity prompts',
        'help' => 'Get told when Beatrax spots a cheaper plan or a place you could save.',
    ],

    'when_heading' => 'When and how',

    'quiet_hours' => [
        'label' => 'Quiet hours',
        'help' => 'No sound or banner during this window — notifications still land in your inbox.',
        'from' => 'From',
        'to' => 'To',
    ],

    'hide_details' => [
        'label' => 'Hide details in notifications',
        'help' => 'Hide amounts and merchant names in the notification banner itself. Turn on if your screen might be visible to others.',
    ],

    'save' => 'Save notification settings',
    'saved' => 'Saved.',

    'other_devices' => [
        'summary' => 'Other devices',
        'empty' => 'No other devices paired yet.',
        'unnamed' => 'Unnamed device',

        'summary_line' => 'reminders :reminders · nudges :nudges · digest :digest · savings :savings',
        'on' => 'on',
        'off' => 'off',
    ],

    'errors' => [
        'save_failed' => "Couldn't save your notification settings. Try again.",
    ],
];
