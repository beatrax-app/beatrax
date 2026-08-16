<?php

declare(strict_types=1);

return [
    'title' => 'Rapports',
    'page_title' => 'Rapports · Beatrax',
    'saved_report' => 'rapport enregistré|rapports enregistrés',
    'pinned_count' => 'épinglés',
    'dismiss' => 'Ignorer',

    'build_new' => 'Créer un nouveau rapport',
    'view_mode_aria' => 'Mode d\'affichage',
    'cards' => 'Cartes',
    'list' => 'Liste',

    'empty' => [
        'heading' => 'Aucun rapport enregistré pour l\'instant',
        'body' => 'Crées-en un ci-dessous et enregistre-le pour le voir ici.',
        'cta' => 'Crée ton premier rapport →',
    ],

    'pin' => [
        'pinned_aria' => 'Épinglé — détacher du tableau de bord',
        'pin_aria' => 'Épingler — épingler au tableau de bord',
        'pinned_title' => 'Épinglé',
        'pin_title' => 'Épingler au tableau de bord',
        'pinned_label' => 'Épinglé',
        'pin_label' => 'Épingler',
    ],

    'open' => 'Ouvrir',
    'edit' => 'Modifier',

    'delete_confirm' => 'Supprimer « :name » ?',
    'delete_report' => 'Supprimer le rapport',
    'cancel' => 'Annuler',
    'delete' => 'Supprimer',
    'delete_aria' => 'Supprimer :name',

    'col' => [
        'name' => 'Nom',
        'summary' => 'Résumé',
        'pinned' => 'Épinglé',
        'actions' => 'Actions',
    ],

    'flash' => [
        'not_found' => 'Rapport introuvable (il a peut-être été supprimé dans un autre onglet).',
        'deleted' => 'Rapport supprimé.',
    ],
    'pin_cap' => 'Tu peux épingler jusqu\'à 3 rapports. Détaches-en un pour ajouter celui-ci.',

    'summary' => [
        'metric' => [
            'spend' => 'Dépenses',
            'income' => 'Revenus',
            'net' => 'Net',
            'net_worth' => 'Patrimoine net',
            'fallback' => 'Montant',
        ],
        'dimension' => [
            'category' => 'catégorie',
            'time_bucket' => 'tranche de temps',
            'counterparty' => 'tiers',
            'account' => 'compte',
            'fallback' => 'catégorie',
        ],
        'period' => [
            'this_month' => 'Ce mois-ci',
            'last_3_months' => '3 derniers mois',
            'last_6_months' => '6 derniers mois',
            'last_12_months' => '12 derniers mois',
            'ytd' => 'Depuis le début de l\'année',
            'this_year' => 'Cette année',
            'custom' => 'Plage personnalisée',
        ],
        'with_dimension' => ':metric · par :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
