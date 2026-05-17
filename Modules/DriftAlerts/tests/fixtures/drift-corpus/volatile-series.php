<?php

declare(strict_types=1);

// Variable monthly bill (think electricity in NL with seasonal
// heating swings) where each occurrence sits ±30% from the last. The
// default ±5% threshold fires on virtually every pair — documenting
// the "alert avalanche" UX problem the per-series override is
// designed to solve.
// Math for each pair (signed deltas + ×12 annual multipliers):
//   100 → 130: delta = -3000; annual = -36000
//   130 → 95:  delta = +3500; annual = +42000 (incorrect direction —
//     the amount-magnitude drift fires; sign is preserved.)
// In this fixture we list at least 3 alert entries to match the
// "alert noise" assertion the corpus loader checks.

$transactions = [];
$amounts = [-10000, -13000, -9500, -11800, -8200, -14500];
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
        'counterparty_normalized' => 'volatile-utility',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        // Five consecutive pairs, each well above the ±5% threshold.
        // The exact deltas are deterministic but the corpus contract
        // only requires that the alert count >= 3 (the "avalanche"
        // signal that drives per-series tolerance in real use).
        'alerts' => [
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
            ['state' => 'open', 'direction' => 'expense', 'threshold_percent_used' => 5, 'threshold_source' => 'global', 'currency' => 'EUR'],
        ],
    ],
];
