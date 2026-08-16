<?php

declare(strict_types=1);

return [
    'page_title' => 'Vérifier les chaînes',
    'heading' => 'Vérifier les chaînes',
    'hint' => 'indice|indices',
    'subtitle' => 'Confirme ou rejette les liens candidats que le résolveur de chaînes n\'a pas pu confirmer automatiquement.',

    'empty_heading' => 'Rien à vérifier',
    'empty_body' => 'Chaque lien de chaîne est confirmé ou rejeté. De nouveaux candidats apparaîtront ici au fil des imports.',

    'auto_confirm_nudge' => 'Encore une confirmation et les liens similaires seront confirmés automatiquement.',

    'confirm' => 'Confirmer',
    'reject' => 'Rejeter',
    'confirm_aria' => 'Confirmer le lien de chaîne :id',
    'reject_aria' => 'Rejeter le lien de chaîne :id',
    'show_more' => 'Afficher plus',

    'kind' => [
        'paypal_funding' => 'Financement PayPal',
        'ics_bulk_settle' => 'Règlement iDEAL groupé',
    ],

    'errors' => [
        'confirm_hint' => 'Ce candidat est un indice — ouvre-le pour rattacher la transaction correspondante avant de confirmer.',
        'reject_hint' => 'Ce candidat est un indice — ouvre-le pour rattacher la transaction correspondante avant de rejeter.',
    ],
];
