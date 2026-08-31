<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Tuntematon kauppias',

    'reasons' => [
        'large' => 'Suuri veloitus',
        'first_time' => 'Ensimmäinen kerta',
        'duplicate' => 'Kaksoisveloitus',
    ],

    'reason_aria' => [
        'first_time' => 'Syy: ensi kertaa esiintyvä kauppias',
        'duplicate' => 'Syy: kaksoisveloitus',
        'generic' => 'Syy: :label',
    ],

    'baseline_to_actual' => 'perustaso :baseline → toteuma: :actual',
    'charged' => 'veloitettu :actual',
    'detected' => 'havaittu :date',
    'sensitivity' => 'herkkyys :percent 100:sta',

    'actions_summary' => 'Toiminnot',

    'chips' => [
        'acknowledge' => 'Kuittaa',
        'acknowledge_aria' => 'Kuittaa kohteen :name poikkeamahälytys',
        'snooze' => 'Torkuta',
        'snooze_options' => 'Torkkuvalinnat',
        'snooze_1w' => '1 viikko',
        'snooze_1m' => '1 kuukausi',
        'snooze_3m' => '3 kuukautta',
        'mark_expected' => 'Merkitse odotetuksi',
        'mark_expected_aria' => 'Merkitse kohteen :name poikkeamahälytys odotetuksi',
        'dismiss' => 'Ohita',
        'dismiss_aria' => 'Ohita kohteen :name poikkeamahälytys',
        'unknown_merchant' => 'tuntematon kauppias',
    ],
];
