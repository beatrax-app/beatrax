<?php

declare(strict_types=1);

return [
    'page_title' => 'Chain hints',
    'heading' => 'Hints',
    'back_to_review' => '← Back to review queue',
    'subtitle' => 'Candidates a matcher emitted without a matching partner. A settlement hint clears itself once the missing charges land; the rest stay until you dismiss them here.',

    'empty_heading' => 'No hints to triage',
    'empty_body' => "When a matcher surfaces a chain it couldn't auto-resolve, it'll show up here.",

    'no_counterparty' => '(no counterparty)',
    'unknown_account' => '(unknown account)',

    'dismiss' => 'Dismiss',
    'dismiss_aria' => 'Dismiss hint :id',
    'dismissed' => 'Hint dismissed.',

    'kind' => [
        'ics_bulk_settle' => 'Bulk iDEAL settlement (out of tolerance)',
        'funded_by_card_hint' => 'Funded by card (hint)',
        'refund_of_hint' => 'Refund (hint)',
    ],

    'evidence' => [
        'tolerance' => 'Tolerance: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'within the flat allowance',
            'percent_2' => 'within the percentage allowance',
            'exceeded' => 'outside the allowance',
            'refund_after_close' => 'refund after the statement closed',
        ],
        'delta_overpaid' => 'Overpaid by :amount',
        'delta_underpaid' => 'Short by :amount',
        'delta_balanced' => 'Balances exactly',
        'covered' => 'Covered transactions: :count',
        'statement' => 'Card statement #:id',
        'card_last4' => 'Card ending in :last4',
        'original_reference' => 'Original order ref: :reference',
    ],
];
