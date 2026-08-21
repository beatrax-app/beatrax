<?php

declare(strict_types=1);

// Spaced exactly 90 days, putting the median interval inside the 80-100 day
// quarterly snap band.

$transactions = [];
$start = new DateTimeImmutable('2024-08-15');
for ($i = 0; $i < 6; $i++) {
    $d = $start->modify('+'.($i * 90).' days');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => -8999,
        'currency' => 'EUR',
        'original_amount_minor' => -8999,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'acme insurance',
        'counterparty_iban' => 'NL90ACME0000000099',
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_count' => 1,
        'series' => [
            [
                'direction' => 'expense',
                'cadence' => 'quarterly',
                'counterparty_normalized' => 'acme insurance',
                'latest_amount_minor' => -8999,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => -3000,
            ],
        ],
    ],
];
