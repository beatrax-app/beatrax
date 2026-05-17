<?php

declare(strict_types=1);

// Monthly salary — 12 occurrences at +€3500 from a single employer IBAN.
// Income detector clusters on the stable counterparty IBAN. Expectation:
// ONE income series, monthly cadence; passes the default €2000 income
// minimum threshold.

$transactions = [];
$start = new DateTimeImmutable('2025-04-25');
for ($i = 0; $i < 12; $i++) {
    $d = $start->modify('+'.$i.' months');
    $transactions[] = [
        'account_id' => null,
        'type' => 'income',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => 350000,
        'currency' => 'EUR',
        'original_amount_minor' => 350000,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'acme bv',
        'counterparty_iban' => 'NL00ACME0000000001',
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_count' => 1,
        'series' => [
            [
                'direction' => 'income',
                'cadence' => 'monthly',
                'counterparty_iban' => 'NL00ACME0000000001',
                'latest_amount_minor' => 350000,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => 350000,
            ],
        ],
    ],
];
