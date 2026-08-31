<?php

declare(strict_types=1);

return [
    'page_title' => 'Chaînes',
    'heading' => 'Chaînes',
    'review_link' => 'File de vérification →',
    'hints_link' => 'Indices →',
    'subtitle' => 'Des achats regroupés en un seul débit. Chaque carte montre un débit et les paiements qui l\'alimentent.',

    'empty_heading' => 'Pas encore de chaînes',
    'empty_body' => 'Importe quelques relevés (banque, PayPal, carte) et le résolveur fera apparaître ici les chaînes entre comptes automatiquement.',

    'no_counterparty' => '(aucun tiers)',
    'leg_count' => ':count paiement|:count paiements',
    'legs_more' => '+ :count autres',
    'state_aria' => 'État : :state',

    'state' => [
        'candidate' => 'Candidat',
        'confirmed' => 'Confirmée',
        'rejected' => 'Rejetée',
    ],

    'kind' => [
        'paypal_funding' => 'Financement PayPal',
        'ics_bulk_settle' => 'Règlement iDEAL groupé',
        'funded_by_card_hint' => 'Financé par carte (indice)',
        'refund_of_hint' => 'Remboursement (indice)',
    ],
];
