<?php

declare(strict_types=1);

// +4.3%, under the default 5% threshold.

$transactions = [];
$amounts = [350000, 350000, 350000, 365000, 365000, 365000];
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
        'alerts' => [],
    ],
];
