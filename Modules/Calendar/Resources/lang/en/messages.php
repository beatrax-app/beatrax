<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Calendar',
        'subtitle' => 'Upcoming payments and your projected daily balance.',
    ],

    'summary' => [
        'computing' => 'Projection updating…',
        'risk' => 'Balance dips below :zero on :date.|Balance dips below :zero on :count days — first: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Previous month',
        'next_month' => 'Next month',
        'accounts' => 'Accounts',
        'popover_aria' => 'Account display settings',
        'no_accounts' => 'No accounts found.',
        'col_account' => 'Account',
        'col_entries' => 'Entries',
        'col_balance' => 'Balance',
        'show_entries_aria' => 'Show entries for :name',
        'count_balance_aria' => 'Count :name in balance',
    ],

    'empty' => [
        'heading' => 'No upcoming payments',
        'body' => 'Connect an account or approve a recurring series to see your projected payments on the calendar.',
        'review' => 'Review recurring →',
    ],

    'weekdays' => [
        'mon' => 'Mon',
        'tue' => 'Tue',
        'wed' => 'Wed',
        'thu' => 'Thu',
        'fri' => 'Fri',
        'sat' => 'Sat',
        'sun' => 'Sun',
    ],

    'grid' => [
        'aria' => ':month calendar',
    ],

    'cell' => [
        'entry' => 'entry|entries',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', projected balance minus :amount',
        'aria_balance_positive' => ', projected balance :amount',
        'overflow' => '+:count more',
        'paid' => 'Paid',
        'missed' => 'Expected — not found',
    ],

    'entry' => [
        'booked_unnamed' => 'Booked payment',
    ],

    'panel' => [
        'aria' => 'Day detail panel',
        'close' => 'Close day panel',
        'start_of_day' => 'Start of day',
        'no_payments' => 'No payments on this day.',
        'date_approximate' => '~ date approximate',
        'series' => '↗ series',
        'counterparty' => '↗ counterparty',
        'transaction' => '↗ transaction',
        'end_of_day' => 'End of day',
    ],
];
