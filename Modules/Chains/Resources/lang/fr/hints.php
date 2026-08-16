<?php

declare(strict_types=1);

return [
    'page_title' => 'Indices de chaîne',
    'heading' => 'Indices',
    'back_to_review' => '← Retour à la file de vérification',
    'subtitle' => 'Candidats qu\'un moteur de correspondance a produits sans partenaire correspondant. Chaque indice se résout de lui-même au prochain passage du résolveur, ou tu peux l\'ignorer ici si tu juges que ça n\'arrivera pas.',

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
];
