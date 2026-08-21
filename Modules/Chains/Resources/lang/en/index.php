<?php

declare(strict_types=1);

return [
    'page_title' => 'Chains',
    'heading' => 'Chains',
    'review_link' => 'Review queue →',
    'hints_link' => 'Hints →',
    'subtitle' => 'Purchases that were collected into a single charge. Each card shows one charge and the payments that fed into it.',

    'empty_heading' => 'No chains yet',
    'empty_body' => 'Import a few statements (bank, PayPal, card) and the resolver will surface cross-account chains here automatically.',

    'no_counterparty' => '(no counterparty)',
    'open_from_row' => 'Open from-row',
    'open_to_row' => 'Open to-row',
    'leg_count' => ':count payment|:count payments',
    'state_aria' => 'State: :state',

    'kind' => [
        'paypal_funding' => 'PayPal funding',
        'ics_bulk_settle' => 'Bulk iDEAL settlement',
        'funded_by_card_hint' => 'Funded by card (hint)',
        'refund_of_hint' => 'Refund (hint)',
    ],
];
