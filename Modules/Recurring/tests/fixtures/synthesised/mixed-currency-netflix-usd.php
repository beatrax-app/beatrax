<?php

declare(strict_types=1);

// USD Netflix subscription settled to EUR — 12 monthly occurrences at
// −$11.99 USD with the settled-EUR amount varying ±3% due to FX rate
// drift. Detector clusters on original currency + amount, so a single
// USD series is produced regardless of EUR settlement drift.
// Expectation: ONE expense series, monthly cadence, currency=USD.

$transactions = [];
$start = new DateTimeImmutable('2025-04-08');
$fxJitter = [0.92, 0.93, 0.91, 0.94, 0.90, 0.93, 0.92, 0.95, 0.91, 0.92, 0.93, 0.94];
for ($i = 0; $i < 12; $i++) {
    $d = $start->modify('+'.$i.' months');
    $settledEur = (int) round(-1199 * $fxJitter[$i]);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => $settledEur,
        'currency' => 'EUR',
        'original_amount_minor' => -1199,
        'original_currency' => 'USD',
        'counterparty_normalized' => 'netflix',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_count' => 1,
        'series' => [
            [
                'direction' => 'expense',
                'cadence' => 'monthly',
                'counterparty_normalized' => 'netflix',
                'latest_amount_minor' => -1199,
                'currency' => 'USD',
            ],
        ],
        'reason' => 'Original-currency clustering keeps the USD series stable despite ±3% EUR settlement drift.',
    ],
];
