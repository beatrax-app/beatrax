<?php

declare(strict_types=1);

// Two identical €49.99 charges to one merchant three days apart, neither on
// an approved recurring series: `duplicate` fires on the second.

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
