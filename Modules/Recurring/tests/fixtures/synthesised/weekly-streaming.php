<?php

declare(strict_types=1);

// Spaced exactly 7 days, putting the median interval in the sub-10-day weekly
// snap band.

$transactions = [];
$start = new DateTimeImmutable('2025-09-01');
for ($i = 0; $i < 12; $i++) {
    $d = $start->modify('+'.($i * 7).' days');
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => -249,
        'currency' => 'EUR',
        'original_amount_minor' => -249,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'mubi',
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
                'cadence' => 'weekly',
                'counterparty_normalized' => 'mubi',
                'latest_amount_minor' => -249,
                'currency' => 'EUR',
                'monthly_equivalent_minor_approx' => -1079,
            ],
        ],
    ],
];
