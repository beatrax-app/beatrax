<?php

declare(strict_types=1);

// Two identical €12.99 charges inside the duplicate window, both members of
// the same approved recurring series: a cadence landing twice, so nothing
// fires.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        ['counterparty' => 'netflix', 'amount_minor' => -1299, 'currency' => 'EUR', 'posted_at' => '2026-06-12', 'on_recurring_series' => true],
    ],
    'transaction' => [
        'counterparty' => 'netflix',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => true,
    ],
    'expected' => [
        'reasons' => [],
    ],
];
