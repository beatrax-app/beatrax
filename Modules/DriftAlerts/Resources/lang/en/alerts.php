<?php

declare(strict_types=1);

return [
    'page_title' => 'Alerts',
    'heading' => 'Alerts',
    'intro_anomaly' => 'Individual charges that look out of the ordinary for you.',
    'intro_drift' => 'Approved recurring series whose latest charge moved outside your threshold.',
    'adjust_threshold' => 'Adjust threshold →',
    'adjust_sensitivity' => 'Adjust sensitivity →',

    'type_aria' => 'Alert type',
    'type' => [
        'drift' => 'Subscription drift',
        'anomaly' => 'Unusual charges',
    ],

    'lifecycle_aria' => 'Alert lifecycle',
    'tabs' => [
        'open' => 'Open',
        'history' => 'History',
        'dismissed' => 'Dismissed',
    ],

    'load_more' => 'Load more',
    'group_count' => ':count drift open|:count drifts open',

    'anomaly_empty' => [
        'open_heading' => 'No unusual charges',
        'open_body' => 'Beatrax watches your spending and flags charges that look out of the ordinary. When something unusual lands, it shows up here.',
        'history_heading' => 'No acknowledged charges yet',
        'history_body' => "Charges you've acknowledged will appear here so you can see what you've already reviewed.",
        'dismissed_heading' => 'Nothing dismissed yet',
        'dismissed_body' => 'When you mark a charge as expected, it lands here with its suppression rule.',
    ],

    'empty_open' => [
        'heading' => 'No open drift alerts',
        'body' => 'Beatrax watches your approved recurring series and flags any whose latest charge differs from the prior amount by more than your threshold. Adjust threshold on',
        'link' => 'Settings → Default drift alert',
    ],
    'empty_history' => [
        'heading' => 'No acknowledged drifts yet',
        'body' => "Acknowledged drift alerts will appear here so you can see what you've already reviewed.",
    ],
    'empty_dismissed' => [
        'heading' => 'Nothing dismissed yet',
        'body' => "When you tell Beatrax you've cancelled a series, that decision lands here with a timestamp.",
    ],

    'row' => [
        'per_year' => '/yr',
        'meta_prior_now' => 'prior :prior → now :now',
        'meta_detected' => 'detected :date',
        'meta_threshold' => 'threshold ±:percent%',
        'meta_eur_equiv' => '(≈ :amount/yr)',
        'cancel_impact' => 'Cancel this → save :amount/yr',
        'cadence_flipped' => 'Cadence flipped — also showing in',
        'cadence_flipped_link' => 'Review recurring',
        'acknowledge' => 'Acknowledge',
        'acknowledge_aria' => 'Acknowledge drift alert :id',
        'snooze' => 'Snooze ▾',
        'snooze_1w' => '1 week',
        'snooze_1m' => '1 month',
        'snooze_3m' => '3 months',
        'model_cancel' => 'Model cancel ↗',
        'model_cancel_aria' => 'Model cancel — models the cancellation in the forecast for drift alert :id',
        'cancelled' => 'I cancelled this',
        'cancelled_aria' => 'I cancelled this — dismisses drift alert :id as cancelled',
    ],

    'toasts' => [
        'acknowledged' => 'Acknowledged',
        'snoozed' => 'Snoozed',
        'dismissed' => 'Dismissed',
        'suppression_added' => 'Suppression rule added — Undo',
        'dismissed_expected' => 'Dismissed as expected',
        'reopened' => 'Reopened',
        'dismissed_cancelled' => 'Dismissed as cancelled',
    ],
];
