<?php

declare(strict_types=1);

return [
    'unknown_merchant' => 'Commerçant inconnu',

    'reasons' => [
        'large' => 'Débit important',
        'first_time' => 'Première fois',
        'duplicate' => 'Doublon',
    ],

    'reason_aria' => [
        'first_time' => 'Motif : commerçant vu pour la première fois',
        'duplicate' => 'Motif : débit en double',
        'generic' => 'Motif : :label',
    ],

    'baseline_to_actual' => 'référence :baseline → réel : :actual',
    'detected' => 'détecté le :date',
    'sensitivity' => 'sensibilité ±:percent%',

    'actions_summary' => 'Actions',

    'chips' => [
        'acknowledge' => 'Prendre en compte',
        'acknowledge_aria' => 'Prendre en compte l\'alerte d\'anomalie pour :name',
        'snooze' => 'Reporter',
        'snooze_options' => 'Options de report',
        'snooze_1w' => '1 semaine',
        'snooze_1m' => '1 mois',
        'snooze_3m' => '3 mois',
        'mark_expected' => 'Marquer comme attendu',
        'mark_expected_aria' => 'Marquer l\'alerte d\'anomalie pour :name comme attendue',
        'dismiss' => 'Ignorer',
        'dismiss_aria' => 'Ignorer l\'alerte d\'anomalie pour :name',
        'unknown_merchant' => 'commerçant inconnu',
    ],
];
