<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Counterparty type: :type',
        'merchant' => 'Merchant',
        'personal' => 'Personal',
        'bank' => 'Bank',
        'government' => 'Government',
        'self' => 'Self',
        'unknown' => 'Unknown',
    ],

    'filter_chips' => [
        'aria' => 'Filter by type',
        'all' => 'All',
        'merchant' => 'Merchants',
        'personal' => 'Personal',
        'bank' => 'Banks',
        'government' => 'Government',
        'self' => 'Self',
        'unknown' => 'Unknown',
    ],

    'default_name' => [
        'bank_fee' => 'Bank fee',
        'account_maintenance' => 'Account maintenance fee',
        'monthly_fee' => 'Monthly account fee',
        'quarterly_fee' => 'Quarterly account fee',
        'annual_fee' => 'Annual account fee',
        'card_fee' => 'Card fee',
        'transaction_fee' => 'Transaction fee',
        'transfer_fee' => 'Transfer fee',
        'withdrawal_fee' => 'Withdrawal fee',
        'transaction_levy' => 'Transaction levy',
        'foreign_transaction_fee' => 'Foreign transaction fee',
        'commission' => 'Commission',
        'debit_interest' => 'Debit interest',
        'overdraft' => 'Overdraft fee',
        'overdraft_interest' => 'Overdraft interest',
        'insufficient_funds' => 'Insufficient funds fee',
        'penalty_fee' => 'Penalty fee',
        'loan_arrangement_fee' => 'Loan arrangement fee',
    ],

    'cp_card' => [
        'aria' => 'Counterparty: :name',
        'recent_aria' => 'Recent activity',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Funding chain: ',
        'join' => ' to ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN hidden — click Show IBAN to reveal',
        'hidden_aria_touch' => 'IBAN hidden — tap Show IBAN to reveal',
        'show' => 'Show IBAN',
        'hide' => 'Hide IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Privacy notice for personal contact',
        'body' => '🔒 This is a personal contact. The IBAN is hidden until you reveal it, and it stays out of exports. Their name still appears wherever their transactions do.',
    ],

    'self_stub' => [
        'aria' => 'Not a real counterparty',
        'heading' => "This isn't really a counterparty",

        'body_rest_html' => " appears here because it shows up in your transactions as the funding leg between accounts. But it's <strong>your own account</strong>, not someone you transact with.",
        'body2' => 'Open the account view for balance, statements, and full transaction history.',
        'open_cta' => 'Open :name account view →',
        'hide_cta' => 'Hide from this list',
        'recent_legs' => 'Recent cross-account legs',
    ],
];
