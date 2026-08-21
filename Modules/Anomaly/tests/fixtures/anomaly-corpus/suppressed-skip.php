<?php

declare(strict_types=1);

// A €23.49 Spotify charge that would fire `large`, muted by a suppression
// rule whose €18.79..€28.19 band brackets it: no alert row is inserted.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'suppression_rules' => [
        [
            'counterparty' => 'spotify',
            'detector' => 'large',
            'direction' => 'expense',
            'amount_band_low_minor' => -2819,
            'amount_band_high_minor' => -1879,
            'currency' => 'EUR',
        ],
    ],
    'history' => [
        ['counterparty' => 'spotify', 'amount_minor' => -999, 'currency' => 'EUR', 'posted_at' => '2026-03-15'],
        ['counterparty' => 'spotify', 'amount_minor' => -999, 'currency' => 'EUR', 'posted_at' => '2026-04-15'],
        ['counterparty' => 'spotify', 'amount_minor' => -999, 'currency' => 'EUR', 'posted_at' => '2026-05-15'],
    ],
    'transaction' => [
        'counterparty' => 'spotify',
        'amount_minor' => -2349,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => [],
        'suppressed' => true,
    ],
];
