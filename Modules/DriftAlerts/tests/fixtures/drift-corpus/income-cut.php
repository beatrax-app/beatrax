<?php

declare(strict_types=1);

// Scenario 6: Salary €3500 → €3325 (−5.0% month-over-month). The
// drop is exactly at the threshold — the detector treats "above the
// threshold" as strictly greater than, so a clean ±5.0% move does
// fire (use 5.001% in the comparison to keep boundary clear; this
// fixture uses a slightly bigger drop for unambiguous expectation).
// Math:
//   delta_minor = 332500 - 350000 = -17500 (signed income, negative)
//   annualized_impact_minor = -17500 × 12 = -210000 (-€2100/yr)

$transactions = [];
$amounts = [350000, 350000, 350000, 332500, 332500, 332500];
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
                'latest_amount_minor' => 332500,
                'delta_minor' => -17500,
                'annualized_impact_minor' => -210000,
                'threshold_percent_used' => 5,
                'threshold_source' => 'global',
                'currency' => 'EUR',
            ],
        ],
    ],
];
