<?php

declare(strict_types=1);

// A €4.50 charge, hugely above the merchant's €0.99 baseline but under the
// user's €10.00 floor: the floor gates all three detectors, so nothing fires.

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
