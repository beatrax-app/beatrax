<?php

declare(strict_types=1);

// A prior of 0 (a refunded or waived period) would divide by zero.

$transactions = [
    [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => '2025-03-15',
        'booked_at' => '2025-03-15',
        'amount_minor' => 0,
        'currency' => 'EUR',
        'original_amount_minor' => 0,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'zero-prior-series',
        'counterparty_iban' => null,
    ],
    [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => '2025-04-15',
        'booked_at' => '2025-04-15',
        'amount_minor' => -1499,
        'currency' => 'EUR',
        'original_amount_minor' => -1499,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'zero-prior-series',
        'counterparty_iban' => null,
    ],
];

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [],
    ],
];
