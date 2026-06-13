<?php

declare(strict_types=1);

// duplicate-in-window: two identical €49.99 charges to the same merchant
// three days apart. The duplicate-charge detector (D-10: same counterparty
// + exact same amount within the short window) fires a `duplicate` reason
// on the second charge. Neither charge is on an approved recurring series.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        ['counterparty' => 'coolblue', 'amount_minor' => -4999, 'currency' => 'EUR', 'posted_at' => '2026-06-12', 'on_recurring_series' => false],
    ],
    'transaction' => [
        'counterparty' => 'coolblue',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => ['duplicate'],
    ],
];
