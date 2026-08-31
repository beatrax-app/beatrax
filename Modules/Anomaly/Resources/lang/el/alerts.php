<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Άγνωστος έμπορος',

    'reasons' => [
        'large' => 'Μεγάλη χρέωση',
        'first_time' => 'Πρώτη φορά',
        'duplicate' => 'Διπλότυπη',
    ],

    'reason_aria' => [
        'first_time' => 'Αιτία: έμπορος για πρώτη φορά',
        'duplicate' => 'Αιτία: διπλότυπη χρέωση',
        'generic' => 'Αιτία: :label',
    ],

    'baseline_to_actual' => 'βάση :baseline → πραγματικό: :actual',
    'charged' => 'χρεώθηκε :actual',
    'detected' => 'εντοπίστηκε :date',
    'sensitivity' => 'ευαισθησία :percent στα 100',

    'actions_summary' => 'Ενέργειες',

    'chips' => [
        'acknowledge' => 'Επιβεβαίωση',
        'acknowledge_aria' => 'Επιβεβαίωση ειδοποίησης ανωμαλίας για :name',
        'snooze' => 'Αναβολή',
        'snooze_options' => 'Επιλογές αναβολής',
        'snooze_1w' => '1 εβδομάδα',
        'snooze_1m' => '1 μήνας',
        'snooze_3m' => '3 μήνες',
        'mark_expected' => 'Σήμανση ως αναμενόμενη',
        'mark_expected_aria' => 'Σήμανση της ειδοποίησης ανωμαλίας για :name ως αναμενόμενης',
        'dismiss' => 'Απόρριψη',
        'dismiss_aria' => 'Απόρριψη ειδοποίησης ανωμαλίας για :name',
        'unknown_merchant' => 'άγνωστος έμπορος',
    ],
];
