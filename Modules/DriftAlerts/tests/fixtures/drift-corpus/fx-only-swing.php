<?php

declare(strict_types=1);

// Netflix USD $11.99 stable across 6 months; the EUR
// settlement amount drifts from €11.20 down to €10.80 purely because
// of FX rate fluctuation. The drift detector compares ORIGINAL
// currency only (per the FX-exclusion invariant) so USD stability
// means zero alerts regardless of EUR shadow drift. Carry-forward
// of the project-wide multi-currency original-only comparison rule.

$transactions = [];
// USD stable at -1199; EUR settlement drifts via fx jitter.
$fxEur = [-1120, -1100, -1090, -1080, -1095, -1080];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-08', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $fxEur[$i],
        'currency' => 'EUR',
        'original_amount_minor' => -1199,
        'original_currency' => 'USD',
        'counterparty_normalized' => 'netflix',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [],
    ],
];
