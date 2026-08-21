<?php

declare(strict_types=1);

// The USD price holds at $11.99 while the EUR settlement drifts on FX alone.
// The detector compares original currency only, so nothing fires.

$transactions = [];
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
