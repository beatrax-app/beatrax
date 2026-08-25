<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Esercente sconosciuto',

    'reasons' => [
        'large' => 'Addebito elevato',
        'first_time' => 'Prima volta',
        'duplicate' => 'Duplicato',
    ],

    'reason_aria' => [
        'first_time' => 'Motivo: esercente mai visto prima',
        'duplicate' => 'Motivo: addebito duplicato',
        'generic' => 'Motivo: :label',
    ],

    'baseline_to_actual' => 'riferimento :baseline → effettivo: :actual',
    'detected' => 'rilevato il :date',
    'sensitivity' => 'sensibilità :percent su 100',

    'actions_summary' => 'Azioni',

    'chips' => [
        'acknowledge' => 'Conferma',
        'acknowledge_aria' => "Conferma l'avviso di anomalia per :name",
        'snooze' => 'Posticipa',
        'snooze_options' => 'Opzioni di posticipo',
        'snooze_1w' => '1 settimana',
        'snooze_1m' => '1 mese',
        'snooze_3m' => '3 mesi',
        'mark_expected' => 'Segna come previsto',
        'mark_expected_aria' => "Segna come previsto l'avviso di anomalia per :name",
        'dismiss' => 'Ignora',
        'dismiss_aria' => "Ignora l'avviso di anomalia per :name",
        'unknown_merchant' => 'esercente sconosciuto',
    ],
];
