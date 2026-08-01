<?php

declare(strict_types=1);

return [
    'page_title' => 'Dashboard',
    'subtitle' => 'This period at a glance.',

    'previous_period' => 'Previous period',
    'today' => 'Today',
    'next_period' => 'Next period',

    'totals_aria' => 'This period totals',
    'totals_aria_currency' => 'This period totals — :currency',
    'in' => 'In',
    'out' => 'Out',
    'net' => 'Net',

    'status_tiles_aria' => 'Status tiles',
    'email_scan_health' => 'Email scan health — :count connected',
    'inbox_one' => 'inbox',
    'inbox_many' => 'inboxes',

    'top_spending' => 'Top spending',
    'no_expenses' => 'No categorized expenses yet.',

    'recent_transactions' => 'Recent transactions',
    'view_all' => 'View all',
    'nothing_period' => 'Nothing here for this period.',
    'th_date' => 'Date',
    'th_counterparty' => 'Counterparty',
    'th_category' => 'Category',
    'th_amount' => 'Amount',
    'uncategorized' => 'Uncategorized',

    'reauth' => [
        'title' => 'An inbox needs reconnecting.',
        'body' => "One or more inboxes were signed out — beatrax can't scan them until you reconnect.",
        'link' => 'Go to Inboxes',
        'dismiss' => 'Dismiss',
    ],

    'failed_chain' => [
        'title' => 'Chain resolution failed.',
        'body' => 'One or more chain-resolution jobs hit an error.',
        'link' => 'Open Queue Inspector',
    ],
];
