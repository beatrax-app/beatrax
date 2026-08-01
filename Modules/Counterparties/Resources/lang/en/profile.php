<?php

declare(strict_types=1);

return [
    'page_title' => 'Counterparty',
    'fallback_account' => 'Account',
    'fallback_counterparty' => 'Counterparty',

    'edit_display_name' => 'Edit display name',

    'hero_net_received' => 'Net received',
    'hero_12mo_total' => '12-month total',
    'hero_transactions' => 'Transactions',
    'hero_first_seen' => 'First seen',

    'tabs' => [
        'overview' => 'Overview',
        'transactions' => 'Transactions',
        'chains' => 'Chains',
        'aliases' => 'Aliases',
        'transfers' => 'Transfers',
        'entries' => 'Entries',
        'payments' => 'Payments',
        'tax_years' => 'Tax years',
    ],

    'tab_note_personal' => '— no funding chains for personal contacts',
    'tab_note_bank' => "— bank-fee counterparty doesn't generate funding chains",
    'tab_note_government' => '— no funding chains for government counterparties',

    'recent_activity' => 'Recent activity',
    'recurring' => 'Recurring',
    'uncategorized' => 'Uncategorized',
    'no_recent_transactions' => 'No transactions on file for this counterparty yet.',
    'see_all' => 'See all :count →',

    'bank' => [
        'fees_heading' => 'Bank fees by category',
        'no_fees' => 'No fees recorded on this counterparty yet.',
    ],

    'government' => [
        'intro' => 'Annual breakdown across all years with activity. Current year is emphasized.',
        'no_payments' => 'No payments recorded yet for this counterparty.',
    ],

    'merchant' => [
        'categories' => 'Categories',

        'categories_empty_html' => 'No categories yet — uncategorized transactions appear in <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Categorization</a>.',
        'no_recurring' => 'No recurring patterns detected.',
        'per_month_suffix' => '/mo',
        'funding_chain' => 'Funding chain',
        'no_funding_chain' => 'No funding chain detected yet. Imports of ASN + PayPal data are required for funding-chain resolution.',
        'open_chains' => 'Open Chains review →',
    ],

    'personal' => [
        'contact' => 'Contact',
        'add_tag' => '+ Add tag',
        'no_recurring' => 'No recurring detected — personal transfers rarely follow a strict cadence; even regular rent splits may shift dates.',
    ],

    'unknown' => [
        'not_labelled_heading' => "This counterparty isn't labelled yet",
        'not_labelled_body' => 'Labelling unknowns helps the dashboard surface accurate monthly totals and funding chains.',
        'label_cta' => 'Label this counterparty',
    ],

    'support' => [
        'contact_help' => 'Contact & help',
        'sign_in_apply' => 'Sign in · apply',
        'your_rights' => 'Your rights · object',
        'cancel' => 'Cancel',
        'help_support' => 'Help & support',
        'cheaper_plan' => 'Cheaper plan',
        'aria_gov' => 'Getting help',
        'aria_merchant' => 'Support and cancelling',
        'heading_gov' => 'Getting help',
        'heading_merchant' => 'Support & cancelling',
        'cancel_by_email' => 'Cancel by email',
    ],
];
