<?php

declare(strict_types=1);

// duplicate-recurring-excluded: two identical €12.99 charges to the same
// merchant inside the duplicate window, BUT both belong to the same
// approved recurring series. Per D-10 the duplicate detector excludes
// charges on a known recurring series, so NO `duplicate` reason fires —
// this is the legitimate "billed twice because the series cadence landed
// twice in the window" case, not a real duplicate.

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
