<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Sensibilité des alertes',
    'sensitivity_help' => 'À quel point Beatrax juge vite un débit inhabituel pour ce commerçant ou cette catégorie, de 1 à 100. Plus haut signale davantage.',

    'min_amount_label' => 'Montant minimum du débit',
    'min_amount_help' => 'Ignore les anomalies sur les débits inférieurs à ce montant. Stocké en sous-unités (:symbol) — :minor signifie :example.',

    'save' => 'Enregistrer les paramètres d\'anomalie',
    'saved' => 'Enregistré.',

    'suppression' => [
        'summary' => 'Règles d\'exclusion',
        'empty' => 'Aucune règle d\'exclusion pour l\'instant. Dès que tu marques un débit comme attendu, une règle apparaît ici.',
        'remove' => 'Retirer',
        'remove_aria' => 'Retirer la règle d\'exclusion',
        'removed_toast' => 'Règle retirée',
    ],

    'unknown_merchant' => 'Commerçant inconnu',

    'detectors' => [
        'large' => 'Débit important',
        'first_time' => 'Première fois',
        'duplicate' => 'Doublon',
    ],

    'errors' => [
        'sensitivity_range' => 'La sensibilité doit être comprise entre 1 et 100.',
        'min_amount_negative' => 'Le montant minimum du débit ne peut pas être négatif.',
    ],
];
