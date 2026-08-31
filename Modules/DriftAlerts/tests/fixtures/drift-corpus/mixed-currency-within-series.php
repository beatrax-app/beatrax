<?php

declare(strict_types=1);

// The same series re-billed in another currency. USD -1299 minus EUR -1099 is
// not a price move at all, and the difference was stamped with the series
// currency and reported as a EUR 24.00/yr saving.

$transactions = [];
$rows = [
    [-1299, 'USD'],
    [-1299, 'USD'],
    [-1299, 'USD'],
    [-1099, 'EUR'],
];
foreach ($rows as $i => [$amount, $currency]) {
    $date = sprintf('2025-%02d-08', $i + 1);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amount,
        'currency' => $currency,
        'original_amount_minor' => $amount,
        'original_currency' => $currency,
        'counterparty_normalized' => 'redenominated-saas',
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
