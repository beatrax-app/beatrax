<?php

declare(strict_types=1);

// Two employers paying the same +€2200 on the same cadence: the differing IBANs
// are the only thing separating them into two series.

$transactions = [];
$start = new DateTimeImmutable('2025-10-25');
for ($i = 0; $i < 6; $i++) {
    $d = $start->modify('+'.$i.' months');
    $transactions[] = [
        'account_id' => null,
        'type' => 'income',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => 220000,
        'currency' => 'EUR',
        'original_amount_minor' => 220000,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'employer a',
        'counterparty_iban' => 'NL44EMPA0000000001',
    ];
    $transactions[] = [
        'account_id' => null,
        'type' => 'income',
        'posted_at' => $d->format('Y-m-d'),
        'booked_at' => $d->format('Y-m-d'),
        'amount_minor' => 220000,
        'currency' => 'EUR',
        'original_amount_minor' => 220000,
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'employer b',
        'counterparty_iban' => 'NL52EMPB0000000002',
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_count' => 2,
        'series' => [
            [
                'direction' => 'income',
                'cadence' => 'monthly',
                'counterparty_iban' => 'NL44EMPA0000000001',
                'latest_amount_minor' => 220000,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => 220000,
            ],
            [
                'direction' => 'income',
                'cadence' => 'monthly',
                'counterparty_iban' => 'NL52EMPB0000000002',
                'latest_amount_minor' => 220000,
                'currency' => 'EUR',
                'monthly_equivalent_minor' => 220000,
            ],
        ],
    ],
];
