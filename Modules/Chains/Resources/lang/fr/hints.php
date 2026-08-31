<?php

declare(strict_types=1);

return [
    'page_title' => 'Indices de chaîne',
    'heading' => 'Indices',
    'back_to_review' => '← Retour à la file de vérification',
    'subtitle' => "Candidats émis par un comparateur sans contrepartie correspondante. Un indice de règlement disparaît de lui-même dès que les débits manquants arrivent ; les autres restent jusqu'à ce que vous les écartiez ici.",

    'empty_heading' => 'Aucun indice à trier',
    'empty_body' => 'Quand un moteur de correspondance fait remonter une chaîne qu\'il n\'a pas pu résoudre automatiquement, elle apparaît ici.',

    'no_counterparty' => '(aucun tiers)',
    'unknown_account' => '(compte inconnu)',

    'dismiss' => 'Ignorer',
    'dismiss_aria' => 'Ignorer l\'indice :id',
    'dismissed' => 'Indice ignoré.',

    'kind' => [
        'ics_bulk_settle' => 'Règlement iDEAL groupé (hors tolérance)',
        'funded_by_card_hint' => 'Financé par carte (indice)',
        'refund_of_hint' => 'Remboursement (indice)',
    ],

    'evidence' => [
        'tolerance' => 'Tolérance : :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'dans la marge fixe',
            'percent_2' => 'dans la marge en pourcentage',
            'exceeded' => 'hors marge',
            'refund_after_close' => 'remboursement après clôture',
        ],
        'delta_overpaid' => 'Trop-payé de :amount',
        'delta_underpaid' => 'Manque :amount',
        'delta_balanced' => 'Équilibre exact',
        'covered' => 'Transactions couvertes : :count',
        'statement' => 'Relevé de carte n° :id',
        'card_last4' => 'Carte se terminant par :last4',
        'original_reference' => 'Référence de commande d’origine : :reference',
    ],
];
