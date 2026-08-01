<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Unknown merchant',

    'reasons' => [
        'large' => 'Large charge',
        'first_time' => 'First time',
        'duplicate' => 'Duplicate',
    ],

    'reason_aria' => [
        'first_time' => 'Reason: first-time merchant',
        'duplicate' => 'Reason: duplicate charge',
        'generic' => 'Reason: :label',
    ],

    'baseline_to_actual' => 'baseline :baseline → actual: :actual',
    'detected' => 'detected :date',
    'sensitivity' => 'sensitivity ±:percent%',

    'actions_summary' => 'Actions',

    'chips' => [
        'acknowledge' => 'Acknowledge',
        'acknowledge_aria' => 'Acknowledge anomaly alert for :name',
        'snooze' => 'Snooze',
        'snooze_options' => 'Snooze options',
        'snooze_1w' => '1 week',
        'snooze_1m' => '1 month',
        'snooze_3m' => '3 months',
        'mark_expected' => 'Mark as expected',
        'mark_expected_aria' => 'Mark anomaly alert for :name as expected',
        'dismiss' => 'Dismiss',
        'dismiss_aria' => 'Dismiss anomaly alert for :name',
        'unknown_merchant' => 'unknown merchant',
    ],
];
