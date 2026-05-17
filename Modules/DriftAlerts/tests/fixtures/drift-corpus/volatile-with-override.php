<?php

declare(strict_types=1);

// Scenario 14: Volatile monthly bill with a per-series threshold
// override of 50%. Every consecutive pair sits inside ±50% so the
// detector fires zero alerts — the graceful escape valve for
// naturally-volatile series like electricity or freelance income.
// Pairwise drift magnitudes: 10000→13000 (+30%), 13000→9500 (-26.9%),
// 9500→11800 (+24.2%), 11800→11000 (-6.8%), 11000→14500 (+31.8%).
// All within ±50% so the override absorbs each.

$transactions = [];
$amounts = [-10000, -13000, -9500, -11800, -11000, -14500];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-05', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'volatile-utility-tolerant',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_drift_threshold_percent' => 50,
        'alerts' => [],
    ],
];
