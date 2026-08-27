<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Non catégorisé',
    'title' => 'Rapports',
    'page_title' => 'Rapports · Beatrax',
    'subtitle' => 'Compose un rapport à partir de ton registre.',
    'controls_aria' => 'Commandes du rapport',
    'result_aria' => 'Résultat du rapport',
    'dismiss' => 'Ignorer',

    'metric' => [
        'heading' => 'Indicateur',
        'spend' => 'Dépenses',
        'income' => 'Revenus',
        'net' => 'Net',
        'net_worth' => 'Patrimoine net',
        'fallback' => 'Montant',
    ],

    'group_by' => 'Grouper par',

    'dimension' => [
        'category' => 'Catégorie',
        'time_bucket' => 'Tranche de temps',
        'counterparty' => 'Tiers',
        'account' => 'Compte',
    ],

    'period' => [
        'heading' => 'Période',
        'this_month' => 'Ce mois-ci',
        'last_3_months' => '3 derniers mois',
        'last_6_months' => '6 derniers mois',
        'last_12_months' => '12 derniers mois',
        'ytd' => 'Depuis le début de l\'année',
        'this_year' => 'Cette année',
        'custom' => 'Plage personnalisée',
        'from' => 'Du',
        'to' => 'Au',
        'error' => [
            'incomplete' => 'Choisissez une date de début et une date de fin.',
            'malformed' => 'Utilisez une date valide au format AAAA-MM-JJ.',
            'inverted' => 'La date de fin précède la date de début.',
        ],
    ],

    'currency' => [
        'heading' => 'Devise',
        'aria' => 'Mode de devise',
        'base' => 'Base',
        'original' => 'D\'origine',
    ],

    'granularity' => [
        'heading' => 'Granularité',
        'aria' => 'Granularité temporelle',
        'monthly' => 'Mensuelle',
        'weekly' => 'Hebdomadaire',
    ],

    'filters' => [
        'heading' => 'Filtres',
        'net_worth_note' => 'La valeur nette est un solde : seul le filtre de compte s’applique.',
    ],

    'compare' => 'Comparer à la période précédente',

    'viz' => [
        'heading' => 'Visualisation',
        'table' => 'Tableau',
        'bar' => 'Barres',
        'line' => 'Courbe',
        'donut' => 'Anneau',
    ],

    'actions' => [
        'update_report' => 'Mettre à jour le rapport',
        'save_report' => 'Enregistrer le rapport',
        'report_name' => 'Nom du rapport',
        'update' => 'Mettre à jour',
        'save' => 'Enregistrer',
        'cancel' => 'Annuler',
        'export_csv' => 'Exporter en CSV',
    ],

    'updating' => '… Mise à jour',

    'empty' => [
        'heading' => 'Rien à afficher pour cette sélection',
        'body' => 'Essaie d\'élargir la plage de dates ou de retirer un filtre.',
    ],

    'total_prefix' => 'Total',
    'total' => 'Total',
    'vs_previous' => 'vs période précédente',
    'view_transactions' => 'Voir les transactions',

    'fx_excluded' => ':count compte non converti — aucun taux disponible|:count comptes non convertis — aucun taux disponible',

    'group_header' => [
        'category' => 'Catégorie',
        'counterparty' => 'Tiers',
        'account' => 'Compte',
        'month' => 'Mois',
        'default' => 'Groupe',
    ],

    'chart' => [
        'bar_title' => 'Clique sur une barre pour voir ses transactions',
        'line_title' => 'Clique sur un point pour voir ses transactions',
        'donut_title' => 'Clique sur un segment pour voir ses transactions',
    ],

    'flash' => [
        'saved' => 'Rapport enregistré.',
        'updated' => 'Rapport mis à jour.',
    ],

    'filter' => [
        'account' => 'Compte',
        'account_count' => ':count compte|:count comptes',
        'remove_account' => 'Retirer le filtre de compte',
        'account_dialog' => 'Filtre de compte',

        'category' => 'Catégorie',
        'category_count' => ':count catégorie|:count catégories',
        'remove_category' => 'Retirer le filtre de catégorie',
        'category_dialog' => 'Filtre de catégorie',

        'counterparty' => 'Tiers',
        'counterparty_count' => ':count tiers|:count tiers',
        'remove_counterparty' => 'Retirer le filtre de tiers',
        'counterparty_dialog' => 'Filtre de tiers',

        'amount' => 'Montant',
        'remove_amount' => 'Retirer le filtre de montant',
        'amount_dialog' => 'Filtre de montant',
        'dir_both' => 'Les deux',
        'dir_in' => 'Entrées',
        'dir_out' => 'Sorties',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Montant minimum',
        'max_aria' => 'Montant maximum',
    ],

    'other_movement' => 'Frais et ajustements (non comptés ci-dessus)',
    'other_movement_with_refunds' => 'Frais, remboursements et ajustements (non comptés ci-dessus)',
];
