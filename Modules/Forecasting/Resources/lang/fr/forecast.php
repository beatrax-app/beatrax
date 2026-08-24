<?php

declare(strict_types=1);

return [
    'heading' => 'Prévisions',
    'page_title' => 'Prévisions',
    'subtitle' => 'Où va ton solde — sur les 30 à 365 prochains jours.',
    'adjust_buffers' => 'Ajuster les réserves',

    'empty_heading' => 'Pas encore de données de prévision',
    'empty_body' => 'Connecte un compte ou approuve une série récurrente pour voir ton solde projeté sur les prochaines semaines.',
    'empty_start' => 'Commence par',
    'empty_import_link' => 'importer un relevé',
    'empty_or' => 'ou',
    'empty_recurring_link' => 'vérifier les schémas récurrents',

    'account_tablist' => 'Compte',
    'all_accounts' => 'Tous les comptes',

    'horizon_label' => 'Horizon de prévision',
    'n_days' => ':days jour|:days jours',

    'view_by_funder' => 'Afficher par financeur',
    'view_by_funder_hint' => 'Regroupe les séries résolues par chaîne sur le compte qui les paie réellement.',

    'scenario_group' => 'Scénario',
    'baseline' => 'Référence',
    'scenario_word' => 'Scénario',
    'new_scenario' => '+ Nouveau scénario',
    'scenario_name_placeholder' => 'Nom du scénario',
    'new_scenario_aria' => 'Nom du nouveau scénario',
    'create_scenario' => 'Créer le scénario',
    'cancel' => 'Annuler',

    'aggregate_subtitle' => 'Solde combiné de tous les comptes, projeté sur le :days prochain jour.|Solde combiné de tous les comptes, projeté sur les :days prochains jours.',

    'today' => 'aujourd\'hui',
    'on_day' => 'au jour',

    'edit_buffer_aria' => 'Modifier la réserve minimale pour :name',
    'buffer_not_set' => 'Réserve : non définie',
    'buffer_set' => 'Réserve : :amount',

    'shortfall' => 'Le déficit commence le :date — :amount sous ta réserve de :buffer',

    'compared_against_baseline' => 'Comparé à la référence ci-dessus',

    'scenario_editor_aria' => 'Éditeur de scénario',
    'series_confidence' => 'Confiance des séries',
    'no_series_contribute' => 'Aucune série ne contribue encore à la prévision de ce compte.',

    'net_diff' => 'Écart net',
    'net_diff_section_aria' => 'Écart net entre la référence et le scénario aux horizons 30 / 60 / 90 jours',
    'net_diff_delta_aria' => 'Écart net au jour :day : :value, le scénario est :state',
    'better_than_baseline' => 'meilleur que la référence',
    'worse_than_baseline' => 'moins bon que la référence',
    'equal_to_baseline' => 'égal à la référence',
    'at_day' => 'au jour :day',

    'updating' => 'Mise à jour',
    'chart_noscript' => 'Le graphique nécessite JavaScript. La plage couvre :days jour.|Le graphique nécessite JavaScript. La plage couvre :days jours.',
    'total_balance' => 'Solde total',

    'per_month_suffix' => '/mois',
    'confidence_chip_aria' => ':name, confiance :confidence — la plage de projection représente :percent pour cent de l\'estimation ponctuelle',

    'highlights_title' => 'Points clés de la prévision',
    'highlights_shortfall_aria' => ':count fenêtre de déficit active dans les :days prochains jours|:count fenêtres de déficit actives dans les :days prochains jours',
    'on_date_suffix' => ' le :date',
    'shortfall_window' => ':count fenêtre de déficit active|:count fenêtres de déficit actives',
    'lowest_in_30_label' => 'Point le plus bas sur 30 jours',
    'next_ics' => 'Prochain règlement ICS : :amount le :date',
    'ics_overdue' => 'Règlement ICS en retard : :amount, échu le :date',
];
