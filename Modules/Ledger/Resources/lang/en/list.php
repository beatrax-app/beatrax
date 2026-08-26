<?php

declare(strict_types=1);

return [
    'page_title' => 'Transactions',
    'heading' => 'Transactions',

    'subtitle_searching' => 'Searching all history',
    'subtitle_full' => 'Full history.',
    'subtitle_recent' => 'Recent transactions (last 90 days).',

    'currency_aria' => 'Currency view',
    'currency_eur' => ':code only',
    'currency_original' => 'Original currency',

    'show_recent' => 'Show recent only',
    'show_full' => 'Show full history',

    'empty_period' => 'Nothing here for this period.',

    'loading_more' => 'Loading more transactions',
    'load_more' => 'Load more',

    'split_badge' => 'Split · :count',
    'split_expand_aria' => 'Split across :count category — expand to view|Split across :count categories — expand to view',

    'chain_badge' => 'chain',
    'chain_title' => 'Part of a chain — open this row to view',

    'table' => [
        'date' => 'Date',
        'counterparty' => 'Counterparty',
        'category' => 'Category',
        'tax' => 'Tax',
        'status' => 'Status',
        'amount' => 'Amount',
    ],

    'search' => [
        'placeholder' => 'Search merchant, description, notes…',
        'placeholder_short' => 'Search transactions…',
        'aria' => 'Search transactions',
        'clear_all' => 'Clear all',
        'filters' => 'Filters',
        'open_filters_aria' => 'Open filters',
        'apply' => 'Apply',
        'clear' => 'Clear',

        'count' => ':count transaction|:count transactions',
        'matching_suffix' => 'matching filters',
        'flow' => ':out out / :in in',
    ],

    'no_results' => [
        'heading' => 'Nothing matches',
        'remove_prompt' => 'Try removing a filter that may be narrowing results:',
        'no_match_query' => 'No transactions match “:query” across all history.',
        'no_match_filters' => 'No transactions match the applied filters.',
        'did_you_mean' => 'Did you mean:',
        'account_fallback' => 'Account :id',
        'category_fallback' => 'Category :id',
    ],

    'filter' => [
        'date' => 'Date',
        'account' => 'Account',
        'amount' => 'Amount',
        'category' => 'Category',
        'date_range' => 'Date range',
        'from' => 'From',
        'to' => 'To',
        'custom_range' => 'Custom range ×',
        'after' => 'After :date ×',
        'before' => 'Before :date ×',
        'dir_both' => 'Both',
        'dir_in' => 'In',
        'dir_out' => 'Out',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimum amount',
        'max_aria' => 'Maximum amount',
        'after_aria' => 'After date',
        'before_aria' => 'Before date',
        'acct' => ':count account|:count accounts',
        'cat' => ':count category|:count categories',
        'date_dialog' => 'Date filter',
        'account_dialog' => 'Account filter',
        'amount_dialog' => 'Amount filter',
        'category_dialog' => 'Category filter',
        'remove_date_aria' => 'Remove date filter',
        'remove_account_aria' => 'Remove account filter',
        'remove_amount_aria' => 'Remove amount filter',
        'remove_category_aria' => 'Remove category filter',

        'remove_named_aria' => 'Remove :name filter',
    ],

    'date_preset' => [
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year' => 'This year',
        'last_year' => 'Last year',
    ],
];
