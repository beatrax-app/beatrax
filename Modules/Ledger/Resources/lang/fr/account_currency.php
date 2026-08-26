<?php

declare(strict_types=1);

return [
    'heading' => 'Devise du compte',
    'intro' => 'La devise dans laquelle chaque compte est libellé. Un nouveau compte démarre dans la devise de référence.',
    'no_accounts' => 'Aucun compte pour le moment.',
    'legend' => 'Devise pour :name',
    'label' => 'Devise',
    'help' => 'La devise dans laquelle ce compte présente son solde.',
    'save' => 'Enregistrer la devise',
    'saved' => 'Enregistré',

    'toast' => [
        'updated' => ':name présente désormais ses montants en :currency.',
    ],

    'errors' => [
        'unknown' => 'Cette devise est inconnue de cette installation.',
    ],

    'warning' => [
        'intro' => 'Faire passer ce compte de :from à :to ne fait que le réétiqueter. Rien de ce qui est stocké n’est converti ni réécrit.',
        'baseline' => 'Son solde initial de :amount reste ce montant exact et sera désormais lu en :to.',
        'lines' => 'Ce compte contient actuellement :',
        'reads' => 'Après le changement, ce compte présente sa ligne :to — zéro s’il ne détient rien en :to.',
        'confirm' => 'Changer quand même',
        'keep' => 'Conserver :currency',
    ],
];
