<?php

declare(strict_types=1);

// The control for fx-only-swing: USD and EUR both stable, nothing fires.

$transactions = [];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-08', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => -1100,
        'currency' => 'EUR',
        'original_amount_minor' => -1199,
        'original_currency' => 'USD',
        'counterparty_normalized' => 'us-streaming',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_currency' => 'USD',
        'alerts' => [],
    ],
];
