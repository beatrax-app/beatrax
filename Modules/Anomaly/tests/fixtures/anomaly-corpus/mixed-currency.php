<?php

declare(strict_types=1);

// A Google Play charge billed in USD whose settled-EUR amount lands inside
// the typical band: nothing fires, so the nominal currency alone cannot
// manufacture a `large`.

return [
    'settings' => [
        'anomaly_sensitivity_percent' => 50,
        'anomaly_min_amount_minor' => 1000,
    ],
    'history' => [
        ['counterparty' => 'google-play', 'amount_minor' => -1099, 'currency' => 'USD', 'settled_amount_minor' => -999, 'settled_currency' => 'EUR', 'posted_at' => '2026-03-15'],
        ['counterparty' => 'google-play', 'amount_minor' => -1099, 'currency' => 'USD', 'settled_amount_minor' => -1010, 'settled_currency' => 'EUR', 'posted_at' => '2026-04-15'],
        ['counterparty' => 'google-play', 'amount_minor' => -1099, 'currency' => 'USD', 'settled_amount_minor' => -1005, 'settled_currency' => 'EUR', 'posted_at' => '2026-05-15'],
    ],
    'transaction' => [
        'counterparty' => 'google-play',
        'amount_minor' => -1099,
        'currency' => 'USD',
        'settled_amount_minor' => -1008,
        'settled_currency' => 'EUR',
        'posted_at' => '2026-06-15',
        'direction' => 'expense',
        'on_recurring_series' => false,
    ],
    'expected' => [
        'reasons' => [],
    ],
];
