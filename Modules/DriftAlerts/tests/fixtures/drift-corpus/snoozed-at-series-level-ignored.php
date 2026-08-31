<?php

declare(strict_types=1);

// A series-level snooze, not the per-alert drift_alerts.snoozed_until: the
// series leaves the projectable set, so the evaluator never sees it. Written as
// state 'approved' plus a flag, it described a row Recurring cannot produce and
// the evaluator duly alerted on it.

$transactions = [];
$amounts = [-999, -999, -999, -1199, -1199, -1199];
for ($i = 0; $i < 6; $i++) {
    $year = 2025 + intdiv($i, 12);
    $month = ($i % 12) + 1;
    $date = sprintf('%04d-%02d-15', $year, $month);
    $transactions[] = [
        'account_id' => null,
        'type' => 'expense',
        'posted_at' => $date,
        'booked_at' => $date,
        'amount_minor' => $amounts[$i],
        'currency' => 'EUR',
        'original_amount_minor' => $amounts[$i],
        'original_currency' => 'EUR',
        'counterparty_normalized' => 'series-snoozed-merchant',
        'counterparty_iban' => null,
    ];
}

return [
    'transactions' => $transactions,
    'expected' => [
        'series_state' => 'snoozed',
        'alerts' => [],
    ],
];
