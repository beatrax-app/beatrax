<?php

declare(strict_types=1);

// first-time-large: a €185.00 charge to a never-before-seen merchant. Per
// D-09 the first-time detector fires ONLY when the charge is also large vs
// the user's overall spending — so this case carries BOTH the `first_time`
// and `large` reasons. The empty per-merchant history forces the
// per-category / overall-spending fallback path (D-06).

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        // No prior charges to 'new-electronics-shop'. The overall-spending
        // baseline is built from the user's typical small charges below.
        ['counterparty' => 'spotify', 'amount_minor' => -999, 'currency' => 'EUR', 'posted_at' => '2026-01-15'],
        ['counterparty' => 'albert-heijn', 'amount_minor' => -3400, 'currency' => 'EUR', 'posted_at' => '2026-02-02'],
        ['counterparty' => 'netflix', 'amount_minor' => -1299, 'currency' => 'EUR', 'posted_at' => '2026-02-15'],
        ['counterparty' => 'albert-heijn', 'amount_minor' => -2890, 'currency' => 'EUR', 'posted_at' => '2026-03-04'],
    ],
    'transaction' => [
        'counterparty' => 'new-electronics-shop',
        'amount_minor' => -18500,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => ['large', 'first_time'],
    ],
];
