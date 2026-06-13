<?php

declare(strict_types=1);

// large-below: a €10.49 Spotify charge against a €9.99 baseline. Only a
// ~5% increase — well within the typical spread at the default sensitivity,
// so the large-vs-typical detector does NOT fire. Above the €10 floor, so
// the floor is not what suppresses it; the statistical threshold is.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        ['counterparty' => 'spotify', 'amount_minor' => -999, 'currency' => 'EUR', 'posted_at' => '2026-01-15'],
        ['counterparty' => 'spotify', 'amount_minor' => -999, 'currency' => 'EUR', 'posted_at' => '2026-02-15'],
        ['counterparty' => 'spotify', 'amount_minor' => -1049, 'currency' => 'EUR', 'posted_at' => '2026-03-15'],
        ['counterparty' => 'spotify', 'amount_minor' => -999, 'currency' => 'EUR', 'posted_at' => '2026-04-15'],
        ['counterparty' => 'spotify', 'amount_minor' => -1049, 'currency' => 'EUR', 'posted_at' => '2026-05-15'],
    ],
    'transaction' => [
        'counterparty' => 'spotify',
        'amount_minor' => -1049,
        'currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => [],
    ],
];
