<?php

declare(strict_types=1);

// Scenario 11: First-ever detected occurrence — there is no prior
// amount to compute a delta against. The detector skips evaluation
// when the prior is NULL (divide-by-zero guard). Expected: zero
// alerts. A single transaction is supplied so the recurring_series
// row references exactly one occurrence.

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
