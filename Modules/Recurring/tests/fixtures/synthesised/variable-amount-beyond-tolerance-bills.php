<?php

declare(strict_types=1);

// Variable-amount utility bills — 6 monthly occurrences where amounts
// swing from −€40 to −€140 (well beyond the ±25% default variance
// tolerance applied against any reference anchor). Detector should
// FRAGMENT the candidate so a single "series" is NOT produced under
// default tolerance. Expectation: zero stable series (the corpus test
// asserts the fixture is structurally valid; the contract test in a
// later wave asserts the fragmentation invariant).

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
