<?php

declare(strict_types=1);

return [
    'page_title' => 'Transactions',
    'heading' => 'Transactions',

    'subtitle_searching' => 'Recherche dans tout l\'historique',
    'subtitle_full' => 'Historique complet.',
    'subtitle_recent' => 'Transactions récentes (90 derniers jours).',

    'currency_aria' => 'Affichage des devises',
    'currency_eur' => 'EUR uniquement',
    'currency_original' => 'Devise d\'origine',

    'show_recent' => 'Afficher seulement les récentes',
    'show_full' => 'Afficher tout l\'historique',

    'empty_period' => 'Rien à afficher pour cette période.',

    'loading_more' => 'Chargement d\'autres transactions',
    'load_more' => 'Afficher plus',

    'split_badge' => 'Ventilée · :count',
    'split_expand_aria' => 'Ventilée sur :count catégories — déplie pour voir',

    'chain_badge' => 'chaîne',
    'chain_title' => 'Fait partie d\'une chaîne — ouvre cette ligne pour voir',

    'table' => [
        'date' => 'Date',
        'counterparty' => 'Tiers',
        'category' => 'Catégorie',
        'tax' => 'Fiscal',
        'status' => 'Statut',
        'amount' => 'Montant',
    ],

    'search' => [
        'placeholder' => 'Rechercher un commerçant, une description, des notes…',
        'placeholder_short' => 'Rechercher des transactions…',
        'aria' => 'Rechercher des transactions',
        'clear_all' => 'Tout effacer',
        'filters' => 'Filtres',
        'open_filters_aria' => 'Ouvrir les filtres',
        'apply' => 'Appliquer',
        'clear' => 'Effacer',

        'count' => ':count transaction|:count transactions',
        'matching_suffix' => 'correspondent aux filtres',
        'flow' => ':out sortis / :in entrés',
    ],

    'no_results' => [
        'heading' => 'Aucun résultat',
        'remove_prompt' => 'Essaie de retirer un filtre qui restreint peut-être les résultats :',
        'no_match_query' => 'Aucune transaction ne correspond à « :query » dans tout l\'historique.',
        'no_match_filters' => 'Aucune transaction ne correspond aux filtres appliqués.',
        'did_you_mean' => 'Tu voulais dire :',
        'account_fallback' => 'Compte :id',
        'category_fallback' => 'Catégorie :id',
    ],

    'filter' => [
        'date' => 'Date',
        'account' => 'Compte',
        'amount' => 'Montant',
        'category' => 'Catégorie',
        'date_range' => 'Plage de dates',
        'from' => 'Du',
        'to' => 'Au',
        'custom_range' => 'Plage personnalisée ×',
        'after' => 'Après le :date ×',
        'before' => 'Avant le :date ×',
        'dir_both' => 'Les deux',
        'dir_in' => 'Entrées',
        'dir_out' => 'Sorties',
        'min' => 'Min',
        'max' => 'Max',
        'min_aria' => 'Montant minimum',
        'max_aria' => 'Montant maximum',
        'after_aria' => 'Après la date',
        'before_aria' => 'Avant la date',
        'acct' => ':count compte|:count comptes',
        'cat' => ':count catégorie|:count catégories',
        'date_dialog' => 'Filtre de date',
        'account_dialog' => 'Filtre de compte',
        'amount_dialog' => 'Filtre de montant',
        'category_dialog' => 'Filtre de catégorie',
        'remove_date_aria' => 'Retirer le filtre de date',
        'remove_account_aria' => 'Retirer le filtre de compte',
        'remove_amount_aria' => 'Retirer le filtre de montant',
        'remove_category_aria' => 'Retirer le filtre de catégorie',

        'remove_named_aria' => 'Retirer le filtre :name',
    ],

    'date_preset' => [
        'this_month' => 'Ce mois-ci',
        'last_month' => 'Le mois dernier',
        'this_year' => 'Cette année',
        'last_year' => 'L\'année dernière',
    ],
];
