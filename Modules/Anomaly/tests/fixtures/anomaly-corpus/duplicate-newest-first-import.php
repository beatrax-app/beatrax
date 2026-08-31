<?php

declare(strict_types=1);

// The same double charge as duplicate-in-window, imported newest-first: the
// LATER charge is read first and so carries the LOWER autoincrement id. Many
// bank CSV exports are ordered this way, and a backfilled older statement
// always is.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'transaction' => [
        'counterparty' => 'coolblue',
        'amount_minor' => -4999,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'history_after' => [
        ['counterparty' => 'coolblue', 'amount_minor' => -4999, 'currency' => 'EUR', 'posted_at' => '2026-06-12', 'on_recurring_series' => false],
    ],
    'expected' => [
        'reasons' => ['duplicate'],
    ],
];
