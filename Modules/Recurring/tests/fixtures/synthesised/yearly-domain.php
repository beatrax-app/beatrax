<?php

declare(strict_types=1);

// Exactly two occurrences 365 days apart — the minimum-2-occurrences floor,
// which decides whether a yearly subscription is caught after a single cycle.

$transactions = [];
$start = new DateTimeImmutable('2024-06-12');
for ($i = 0; $i < 2; $i++) {
    $d = $start->modify('+'.($i * 365).' days');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => -1200,
        'currency' => 'EUR',
        'original_amount_minor' => -1200,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'gandi',
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
                'cadence' => 'yearly',
                'counterparty_normalized' => 'gandi',
                'latest_amount_minor' => -1200,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => -100,
            ],
        ],
    ],
];
