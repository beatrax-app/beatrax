<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Alert sensitivity',
    'sensitivity_help' => 'How readily Beatrax calls a charge unusual for that merchant or category, from 1 to 100. Higher flags more.',

    'min_amount_label' => 'Minimum charge amount',
    'min_amount_help' => 'Ignore anomalies on charges under this amount. Stored in minor units (:symbol) — :minor means :example.',

    'save' => 'Save anomaly settings',
    'saved' => 'Saved.',

    'suppression' => [
        'summary' => 'Suppression rules',
        'empty' => 'No suppression rules yet. When you mark a charge as expected, a rule appears here.',
        'remove' => 'Remove',
        'remove_aria' => 'Remove suppression rule',
        'removed_toast' => 'Rule removed',
    ],

    'unknown_merchant' => 'Unknown merchant',

    'detectors' => [
        'large' => 'Large charge',
        'first_time' => 'First time',
        'duplicate' => 'Duplicate',
    ],

    'errors' => [
        'sensitivity_range' => 'Sensitivity must be between 1 and 100.',
        'min_amount_negative' => 'Minimum charge amount cannot be negative.',
    ],
];
