<?php

declare(strict_types=1);

return [
    'title' => 'Reports',
    'page_title' => 'Reports · Beatrax',
    'subtitle' => 'Compose a report from your ledger.',
    'controls_aria' => 'Report controls',
    'result_aria' => 'Report result',
    'dismiss' => 'Dismiss',

    'metric' => [
        'heading' => 'Metric',
        'spend' => 'Spend',
        'income' => 'Income',
        'net' => 'Net',
        'net_worth' => 'Net worth',
        'fallback' => 'Amount',
    ],

    'group_by' => 'Group by',

    'dimension' => [
        'category' => 'Category',
        'time_bucket' => 'Time bucket',
        'counterparty' => 'Counterparty',
        'account' => 'Account',
    ],

    'period' => [
        'heading' => 'Period',
        'this_month' => 'This month',
        'last_3_months' => 'Last 3 months',
        'last_6_months' => 'Last 6 months',
        'last_12_months' => 'Last 12 months',
        'ytd' => 'Year to date',
        'this_year' => 'This year',
        'custom' => 'Custom range',
        'from' => 'From',
        'to' => 'To',
    ],

    'currency' => [
        'heading' => 'Currency',
        'aria' => 'Currency mode',
        'base' => 'Base',
        'original' => 'Original',
    ],

    'granularity' => [
        'heading' => 'Granularity',
        'aria' => 'Time granularity',
        'monthly' => 'Monthly',
        'weekly' => 'Weekly',
    ],

    'filters' => [
        'heading' => 'Filters',
    ],

    'compare' => 'Compare to previous period',

    'viz' => [
        'heading' => 'Visualization',
        'table' => 'Table',
        'bar' => 'Bar',
        'line' => 'Line',
        'donut' => 'Donut',
    ],

    'actions' => [
        'update_report' => 'Update report',
        'save_report' => 'Save report',
        'report_name' => 'Report name',
        'update' => 'Update',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'export_csv' => 'Export CSV',
    ],

    'updating' => '… Updating',

    'empty' => [
        'heading' => 'Nothing to show for this selection',
        'body' => 'Try widening the date range or removing a filter.',
    ],

    'total_prefix' => 'Total',
    'total' => 'Total',
    'vs_previous' => 'vs. previous period',
    'view_transactions' => 'View transactions',

    'fx_excluded' => ':count account not converted — no rate available|:count accounts not converted — no rate available',

    'group_header' => [
        'category' => 'Category',
        'counterparty' => 'Counterparty',
        'account' => 'Account',
        'month' => 'Month',
        'default' => 'Group',
    ],

    'chart' => [
        'bar_title' => 'Click a bar to view its transactions',
        'line_title' => 'Click a point to view its transactions',
        'donut_title' => 'Click a segment to view its transactions',
    ],

    'flash' => [
        'saved' => 'Report saved.',
        'updated' => 'Report updated.',
    ],

    'filter' => [
        'account' => 'Account',
        'account_count' => ':count account|:count accounts',
        'remove_account' => 'Remove account filter',
        'account_dialog' => 'Account filter',

        'category' => 'Category',
        'category_count' => ':count category|:count categories',
        'remove_category' => 'Remove category filter',
        'category_dialog' => 'Category filter',

        'counterparty' => 'Counterparty',
        'counterparty_count' => ':count counterparty|:count counterparties',
        'remove_counterparty' => 'Remove counterparty filter',
        'counterparty_dialog' => 'Counterparty filter',

        'amount' => 'Amount',
        'remove_amount' => 'Remove amount filter',
        'amount_dialog' => 'Amount filter',
        'dir_both' => 'Both',
        'dir_in' => 'In',
        'dir_out' => 'Out',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Minimum amount',
        'max_aria' => 'Maximum amount',
    ],
];
