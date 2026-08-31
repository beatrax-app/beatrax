<?php

declare(strict_types=1);

// Three identical prior charges inside the window. Only the NEAREST one shares
// the recurring series with the charge under test, so which sibling the
// detector resolves decides the answer: nearest => a cadence that landed twice
// (no fire), either of the others => a duplicate.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        ['counterparty' => 'spotify', 'amount_minor' => -1299, 'currency' => 'EUR', 'posted_at' => '2026-06-10', 'on_recurring_series' => false],
        ['counterparty' => 'spotify', 'amount_minor' => -1299, 'currency' => 'EUR', 'posted_at' => '2026-06-11', 'on_recurring_series' => false],
        ['counterparty' => 'spotify', 'amount_minor' => -1299, 'currency' => 'EUR', 'posted_at' => '2026-06-14', 'on_recurring_series' => true],
    ],
    'transaction' => [
        'counterparty' => 'spotify',
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
