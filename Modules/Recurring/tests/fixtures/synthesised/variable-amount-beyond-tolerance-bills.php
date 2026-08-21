<?php

declare(strict_types=1);

// Amounts swinging from −€40 to −€140, far beyond the ±25% default variance
// tolerance, so the candidate has to fragment rather than survive as one series.

$transactions = [];
$start = new DateTimeImmutable('2025-10-12');
$amounts = [-4000, -14000, -4500, -13500, -5000, -12500];
for ($i = 0; $i < 6; $i++) {
    $d = $start->modify('+'.$i.' months');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'utility nv',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_count' => 0,
        'series' => [],
        'reason' => 'Amounts swing beyond the default ±25% variance tolerance; clusters fragment so no single stable series survives.',
    ],
];
