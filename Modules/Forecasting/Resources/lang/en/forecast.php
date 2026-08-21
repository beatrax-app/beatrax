<?php

declare(strict_types=1);

return [
    'heading' => 'Forecast',
    'page_title' => 'Forecast',
    'subtitle' => 'Where your balance is heading — over the next 30 to 365 days.',
    'adjust_buffers' => 'Adjust buffers',

    'empty_heading' => 'No forecast data yet',
    'empty_body' => 'Connect an account or approve a recurring series to see your projected balance over the coming weeks.',
    'empty_start' => 'Start by',
    'empty_import_link' => 'importing a statement',
    'empty_or' => 'or',
    'empty_recurring_link' => 'reviewing recurring patterns',

    'account_tablist' => 'Account',
    'all_accounts' => 'All accounts',

    'horizon_label' => 'Forecast horizon',
    'n_days' => ':days day|:days days',

    'view_by_funder' => 'View by funder',
    'view_by_funder_hint' => 'Collapse chain-resolved series onto the account that actually pays them.',

    'scenario_group' => 'Scenario',
    'baseline' => 'Baseline',
    'scenario_word' => 'Scenario',
    'new_scenario' => '+ New scenario',
    'scenario_name_placeholder' => 'Scenario name',
    'new_scenario_aria' => 'New scenario name',
    'create_scenario' => 'Create scenario',
    'cancel' => 'Cancel',

    'aggregate_subtitle' => 'Combined balance across every account, projected over the next :days day.|Combined balance across every account, projected over the next :days days.',

    'today' => 'today',
    'on_day' => 'on day',

    'edit_buffer_aria' => 'Edit minimum buffer for :name',
    'buffer_not_set' => 'Buffer: not set',
    'buffer_set' => 'Buffer: :amount',

    'shortfall' => 'Shortfall starts :date — :amount below your :buffer buffer',

    'compared_against_baseline' => 'Compared against baseline above',

    'scenario_editor_aria' => 'Scenario editor',
    'series_confidence' => 'Series confidence',
    'no_series_contribute' => "No series contribute to this account's forecast yet.",

    'net_diff' => 'Net diff',
    'net_diff_section_aria' => 'Net diff between baseline and scenario at horizon days 30 / 60 / 90',
    'net_diff_delta_aria' => 'Net difference at day :day: :value, scenario is :state',
    'better_than_baseline' => 'better than baseline',
    'worse_than_baseline' => 'worse than baseline',
    'equal_to_baseline' => 'equal to baseline',
    'at_day' => 'at day :day',

    'updating' => 'Updating',
    'chart_noscript' => 'Chart requires JavaScript. Range covers :days day.|Chart requires JavaScript. Range covers :days days.',
    'total_balance' => 'Total balance',

    'per_month_suffix' => '/mo',
    'confidence_chip_aria' => ':name, :confidence confidence — projection range is :percent percent of the point estimate',

    'highlights_title' => 'Forecast highlights',
    'highlights_shortfall_aria' => ':count active shortfall window in the next :days days|:count active shortfall windows in the next :days days',
    'dips_to' => ':name dips to :amount',
    'on_date_suffix' => ' on :date',
    'shortfall_window' => ':count active shortfall window|:count active shortfall windows',
    'lowest_in_30' => 'Lowest in 30 days: :amount',
    'next_ics' => 'Next ICS settlement: :amount on :date',
];
