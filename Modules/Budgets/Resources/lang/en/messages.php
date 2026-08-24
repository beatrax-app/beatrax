<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budgets',
        'subtitle' => 'Assign it all — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Previous period',
        'next_aria' => 'Next period',
    ],

    'ready' => [
        'label' => 'Ready to assign',
        'overassigned' => "You've assigned more than you have — reduce an envelope or wait for more income.",
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Nothing assigned yet',
        'copy_hint' => "Copy last month's plan, or click into a cell below to start assigning.",
        'first_hint' => 'Click into a cell below to start assigning your first month.',
        'copy_button' => 'Copy last month',
    ],

    'no_categories' => [
        'heading' => 'No expense categories yet',
        'body' => 'Add an expense category to start assigning money to it.',
    ],

    'table' => [
        'category' => 'Category',
        'assigned' => 'Assigned',
        'spent' => 'Spent',
        'available' => 'Available',
        'if_overspent' => 'If overspent',
        'notify_at' => 'Notify at',
        'actions' => 'Actions',
    ],

    'badge' => [
        'carries_negative' => 'Carries negative',
        'unconverted_aria' => 'Spend in a currency with no available rate is not counted here — see the dashboard',
        'unconverted_title' => 'Spend with no available rate is not counted here — see the dashboard',
        'over_budget' => ':count over budget',
    ],

    'row' => [
        'assigned_aria' => 'Assigned for :category',
        'overspend_aria' => 'If :category is overspent',
        'notify_aria' => 'Notify me at percent used for :category',
        'move_money' => 'Move money',
        'move' => 'Move',
    ],

    'overspend' => [
        'reduce' => "Reduce next month's ready-to-assign",
        'carry' => 'Carry the negative in this envelope',
    ],

    'history' => [
        'show' => 'Show history ↓',
        'hide' => 'Hide history ↑',
        'moved_from' => 'Moved from :category',
        'moved_to' => 'Moved to :category',
        'undo' => 'Undo',
    ],

    'phone' => [
        'spent' => 'Spent :amount',
        'available' => 'Available :amount',
        'notify_at' => 'Notify at',
    ],

    'modal' => [
        'move_from' => 'Move from :name',
        'move_from_fallback' => 'envelope',
        'move_to' => 'Move to',
        'no_other' => 'No other envelopes',
        'select' => 'Select an envelope',
        'amount' => 'Amount',
        'available_in' => 'Available in :name: :amount',
        'note' => 'Note (optional)',
        'note_placeholder' => 'e.g. Covering dining overspend',
        'cancel' => 'Cancel',
        'move_funds' => 'Move funds',
    ],

    'glance' => [
        'see_all' => 'See all →',
    ],

    'notices' => [
        'invalid_amount' => 'Enter a valid amount.',
        'threshold_range' => 'Enter a whole number between 1 and 200.',
        'copied_last_month' => 'Copied last month’s plan.',
        'choose_envelope' => 'Choose an envelope to move money to.',
        'amount_positive' => 'Enter an amount greater than zero.',
        'move_failed' => 'Could not complete the move — please try again.',
        'money_moved' => 'Money moved.',
        'move_undone' => 'Move undone.',
    ],

    'errors' => [
        'assigned_negative' => 'Assigned amount cannot be negative.',
        'invalid_overspend_mode' => 'Invalid overspend mode.',
        'threshold_range' => 'Notify threshold must be between 1 and 200.',
        'same_envelope' => 'Source and destination envelope must be different.',
        'non_positive_amount' => 'Invalid or non-positive amount.',
        'category_not_found' => 'Category not found or not accessible by user.',
    ],
];
