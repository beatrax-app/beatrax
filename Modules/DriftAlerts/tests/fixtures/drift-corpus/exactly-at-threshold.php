<?php

declare(strict_types=1);

// A move of exactly the threshold is silent; 5.1% is not. The comparison is
// strictly-greater-than, and a round pair is the only way to pin which side of
// the boundary the equality falls on.

$transactions = [];
$amounts = [-10000, -10000, -10000, -10500, -10500, -10500];
for ($i = 0; $i < 6; $i++) {
    $date = sprintf('2025-%02d-12', $i + 1);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'at-threshold-utility',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [],
    ],
];
