<?php

declare(strict_types=1);

return [
    'title' => 'Reports',
    'page_title' => 'Reports · Beatrax',
    'saved_report' => 'saved report|saved reports',
    'pinned_count' => 'pinned',
    'dismiss' => 'Dismiss',

    'build_new' => 'Build a new report',
    'view_mode_aria' => 'View mode',
    'cards' => 'Cards',
    'list' => 'List',

    'empty' => [
        'heading' => 'No saved reports yet',
        'body' => 'Build one below and save it to see it here.',
        'cta' => 'Build your first report →',
    ],

    'pin' => [
        'pinned_aria' => 'Pinned — unpin from dashboard',
        'pin_aria' => 'Pin — pin to dashboard',
        'pinned_title' => 'Pinned',
        'pin_title' => 'Pin to dashboard',
        'pinned_label' => 'Pinned',
        'pin_label' => 'Pin',
    ],

    'open' => 'Open',
    'edit' => 'Edit',

    'delete_confirm' => 'Delete ":name"?',
    'delete_report' => 'Delete report',
    'cancel' => 'Cancel',
    'delete' => 'Delete',
    'delete_aria' => 'Delete :name',

    'col' => [
        'name' => 'Name',
        'summary' => 'Summary',
        'pinned' => 'Pinned',
        'actions' => 'Actions',
    ],

    'flash' => [
        'not_found' => 'Report not found (it may have been deleted in another tab).',
        'deleted' => 'Report deleted.',
    ],
    'pin_cap' => 'You can pin up to 3 reports. Unpin one to add this.',

    'summary' => [
        'metric' => [
            'spend' => 'Spend',
            'income' => 'Income',
            'net' => 'Net',
            'net_worth' => 'Net worth',
            'fallback' => 'Amount',
        ],
        'dimension' => [
            'category' => 'category',
            'time_bucket' => 'time bucket',
            'counterparty' => 'counterparty',
            'account' => 'account',
            'fallback' => 'category',
        ],
        'period' => [
            'this_month' => 'This month',
            'last_3_months' => 'Last 3 months',
            'last_6_months' => 'Last 6 months',
            'last_12_months' => 'Last 12 months',
            'ytd' => 'Year to date',
            'this_year' => 'This year',
            'custom' => 'Custom range',
        ],
        'with_dimension' => ':metric · by :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
