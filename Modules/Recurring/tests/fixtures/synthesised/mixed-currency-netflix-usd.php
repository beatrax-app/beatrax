<?php

declare(strict_types=1);

// A USD subscription settled to EUR, the settled amount varying ±3% on FX drift.
// Clustering keys on the original currency and amount, so the EUR-side drift
// must not split it.

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
