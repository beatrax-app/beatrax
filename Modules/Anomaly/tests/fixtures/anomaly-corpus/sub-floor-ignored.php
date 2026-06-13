<?php

declare(strict_types=1);

// sub-floor-ignored: a €4.50 charge that is large vs the merchant's €0.99
// baseline in percentage terms, but falls under the user's €10.00 global
// minimum-amount floor (D-11). The floor suppresses ALL three detectors, so
// no reasons fire — trivial-charge noise is filtered regardless of the
// percentage swing.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        ['counterparty' => 'app-store', 'amount_minor' => -99, 'currency' => 'EUR', 'posted_at' => '2026-03-15'],
        ['counterparty' => 'app-store', 'amount_minor' => -99, 'currency' => 'EUR', 'posted_at' => '2026-04-15'],
        ['counterparty' => 'app-store', 'amount_minor' => -99, 'currency' => 'EUR', 'posted_at' => '2026-05-15'],
    ],
    'transaction' => [
        'counterparty' => 'app-store',
        'amount_minor' => -450,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => [],
    ],
];
