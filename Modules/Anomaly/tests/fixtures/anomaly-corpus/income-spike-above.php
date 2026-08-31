<?php

declare(strict_types=1);

// A EUR 9,000.00 payout against a stable EUR 3,000.00 salary. Income is the
// direction the whole corpus was missing: every other fixture is an expense,
// so nothing here compared a POSITIVE latest amount with its own baseline.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        ['counterparty' => 'payroll-bv', 'amount_minor' => 300000, 'currency' => 'EUR', 'posted_at' => '2026-01-25', 'direction' => 'income'],
        ['counterparty' => 'payroll-bv', 'amount_minor' => 300000, 'currency' => 'EUR', 'posted_at' => '2026-02-25', 'direction' => 'income'],
        ['counterparty' => 'payroll-bv', 'amount_minor' => 300000, 'currency' => 'EUR', 'posted_at' => '2026-03-25', 'direction' => 'income'],
        ['counterparty' => 'payroll-bv', 'amount_minor' => 300000, 'currency' => 'EUR', 'posted_at' => '2026-04-25', 'direction' => 'income'],
        ['counterparty' => 'payroll-bv', 'amount_minor' => 300000, 'currency' => 'EUR', 'posted_at' => '2026-05-25', 'direction' => 'income'],
    ],
    'transaction' => [
        'counterparty' => 'payroll-bv',
        'amount_minor' => 900000,
        'currency' => 'EUR',
        'posted_at' => '2026-06-25',
        'direction' => 'income',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => ['large'],
        'baseline_amount_minor' => 300000,
        'latest_amount_minor' => 900000,
    ],
];
