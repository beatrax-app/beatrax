<?php

declare(strict_types=1);

// Irregular gym charges — 5 occurrences with random amounts and
// non-uniform interval spacing (5, 40, 70, 120, 200 day gaps). The
// median interval lands outside all cadence snap bands; detector must
// classify as `irregular` and SKIP the candidate. Expectation: ZERO
// series.

$transactions = [];
$start = new DateTimeImmutable('2025-01-10');
$dayOffsets = [0, 5, 45, 115, 235]; // gaps: 5, 40, 70, 120
$amounts = [-1500, -3200, -5800, -2100, -4400];
foreach ($dayOffsets as $i => $offset) {
    $d = $start->modify('+'.$offset.' days');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'gym pass',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_count' => 0,
        'series' => [],
        'reason' => 'Median interval lands outside weekly/monthly/quarterly/yearly snap bands; cadence=irregular ⇒ candidate skipped.',
    ],
];
