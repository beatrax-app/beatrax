<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Neznámy obchodník',

    'reasons' => [
        'large' => 'Veľká platba',
        'first_time' => 'Prvýkrát',
        'duplicate' => 'Duplicita',
    ],

    'reason_aria' => [
        'first_time' => 'Dôvod: obchodník prvýkrát',
        'duplicate' => 'Dôvod: duplicitná platba',
        'generic' => 'Dôvod: :label',
    ],

    'baseline_to_actual' => 'základ :baseline → skutočnosť: :actual',
    'detected' => 'zistené :date',
    'sensitivity' => 'citlivosť :percent zo 100',

    'actions_summary' => 'Akcie',

    'chips' => [
        'acknowledge' => 'Potvrdiť',
        'acknowledge_aria' => 'Potvrdiť upozornenie na nezvyčajnú platbu — :name',
        'snooze' => 'Odložiť',
        'snooze_options' => 'Možnosti odloženia',
        'snooze_1w' => '1 týždeň',
        'snooze_1m' => '1 mesiac',
        'snooze_3m' => '3 mesiace',
        'mark_expected' => 'Označiť ako očakávané',
        'mark_expected_aria' => 'Označiť upozornenie na nezvyčajnú platbu ako očakávané — :name',
        'dismiss' => 'Zamietnuť',
        'dismiss_aria' => 'Zamietnuť upozornenie na nezvyčajnú platbu — :name',
        'unknown_merchant' => 'neznámy obchodník',
    ],
];
