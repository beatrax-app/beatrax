<?php

declare(strict_types=1);

// One transaction only: with no prior amount there is no ratio to compute.

$transactions = [
    [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => '2025-04-15',
        'booked_at' => '2025-04-15',
        'amount_minor' => -1499,
        'currency' => 'EUR',
        'original_amount_minor' => -1499,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'first-time-series',
        'counterparty_iban' => null,
    ],
];

return [
    'transactions' => $transactions,
    'expected' => [
        'alerts' => [],
    ],
];
