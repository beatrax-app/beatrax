<?php

declare(strict_types=1);

// The merchant reversed the charge. Magnitude and direction share one signed
// integer, so subtracting the refund from the charge read as a 200% rise and
// annualised a EUR 1,200/yr subscription increase out of a refund.

$transactions = [];
$amounts = [-5000, -5000, -5000, 5000];
foreach ($amounts as $i => $amount) {
    $date = sprintf('2025-%02d-20', $i + 1);
    $transactions[] = [
        'account_id' => null,
        'type' => $amount < 0 ? 'expense' : 'refund',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amount,
        'currency' => 'EUR',
        'original_amount_minor' => $amount,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'reversing-merchant',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [],
    ],
];
