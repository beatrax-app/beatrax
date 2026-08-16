<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Calendrier',
        'subtitle' => 'Paiements à venir et solde quotidien prévu.',
    ],

    'summary' => [
        'computing' => 'Mise à jour de la prévision…',
        'risk' => 'Le solde passe sous 0 € le :date.|Le solde passe sous 0 € sur :count jours — premier : :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Mois précédent',
        'next_month' => 'Mois suivant',
        'accounts' => 'Comptes',
        'popover_aria' => 'Paramètres d\'affichage des comptes',
        'no_accounts' => 'Aucun compte trouvé.',
        'col_account' => 'Compte',
        'col_entries' => 'Paiements',
        'col_balance' => 'Solde',
        'show_entries_aria' => 'Afficher les paiements de :name',
        'count_balance_aria' => 'Compter :name dans le solde',
    ],

    'empty' => [
        'heading' => 'Aucun paiement à venir',
        'body' => 'Connecte un compte ou approuve une série récurrente pour voir tes paiements prévus dans le calendrier.',
        'review' => 'Vérifier les récurrences →',
    ],

    'weekdays' => [
        'mon' => 'lun',
        'tue' => 'mar',
        'wed' => 'mer',
        'thu' => 'jeu',
        'fri' => 'ven',
        'sat' => 'sam',
        'sun' => 'dim',
    ],

    'grid' => [
        'aria' => 'Calendrier de :month',
    ],

    'cell' => [
        'entry' => 'paiement|paiements',
        'aria' => ':date : :count :entries',
        'aria_balance_negative' => ', solde prévu moins :amount €',
        'aria_balance_positive' => ', solde prévu :amount €',
        'overflow' => '+:count de plus',
        'paid' => 'Payé',
        'missed' => 'Attendu — introuvable',
    ],

    'panel' => [
        'aria' => 'Panneau de détail du jour',
        'close' => 'Fermer le panneau du jour',
        'start_of_day' => 'Début de journée',
        'no_payments' => 'Aucun paiement ce jour-là.',
        'date_approximate' => '~ date approximative',
        'series' => '↗ série',
        'counterparty' => '↗ tiers',
        'end_of_day' => 'Fin de journée',
    ],
];
