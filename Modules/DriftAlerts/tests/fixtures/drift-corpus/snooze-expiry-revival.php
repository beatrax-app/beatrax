<?php

declare(strict_types=1);

// The expected block describes the POST-revival state: the alert is seeded as
// snoozed with an expired snoozed_until, and the sweep flips it back to open.

$transactions = [];
$amounts = [-999, -999, -999, -1149, -1149, -1149];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-15', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'snooze-revival-merchant',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'expense',
                'baseline_amount_minor' => -999,
                'latest_amount_minor' => -1149,
                'delta_minor' => -150,
                'annualized_impact_minor' => -1800,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
        'transitions' => [
            [
                'from_state' => 'snoozed',
                'to_state' => 'open',
                'transition_reason' => 'detector_revived_snooze',
                'actor' => 'detector',
            ],
        ],
    ],
];
