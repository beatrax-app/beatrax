<?php

declare(strict_types=1);

// Scenario 22: Approved series whose recurring_series.snoozed_until
// is in the future (the Phase 8 series-level snooze). The Recurring-
// side projection excludes snoozed series from the detector's input,
// so the DriftAlerts detector never sees the rows. Zero alerts.
// Distinct from drift_alerts.snoozed_until (the per-alert snooze)
// shipped in this phase.

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
        'series_state' => 'approved',
        'series_snoozed' => true,
        'alerts' => [],
    ],
];
