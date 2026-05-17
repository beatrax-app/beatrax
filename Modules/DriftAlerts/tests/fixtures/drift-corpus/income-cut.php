<?php

declare(strict_types=1);

// Salary €3500 → €3290 (−6.0% month-over-month, strictly greater than
// the default ±5% threshold). The detector applies strict-greater-than
// against the threshold, so an exactly-5.0% move would NOT fire — the
// fixture picks a value past the boundary for unambiguous expectation.
// Math:
//   delta_minor = 329000 - 350000 = -21000 (signed income, negative)
//   annualized_impact_minor = -21000 × 12 = -252000 (-€2520/yr)

$transactions = [];
$amounts = [350000, 350000, 350000, 329000, 329000, 329000];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-25', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'income',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'acme-employer',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [
            [
                'state' => 'open',
                'direction' => 'income',
                'baseline_amount_minor' => 350000,
                'latest_amount_minor' => 329000,
                'delta_minor' => -21000,
                'annualized_impact_minor' => -252000,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
