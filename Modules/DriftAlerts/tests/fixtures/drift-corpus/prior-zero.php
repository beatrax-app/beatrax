<?php

declare(strict_types=1);

// Prior occurrence amount is 0 cents (a refund-zeroed
// or no-charge period). The detector skips evaluation when the prior
// is 0 to avoid divide-by-zero. Expected: zero alerts.

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
