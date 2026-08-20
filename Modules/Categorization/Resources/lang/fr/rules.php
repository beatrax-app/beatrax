<?php

declare(strict_types=1);

return [
    'page_title' => 'Règles',
    'heading' => 'Règles',
    'intro' => 'Catégorise les transactions dès l\'import. Les règles s\'appliquent à toutes les sources — banque, carte, PayPal et reçus par e-mail.',
    'device_local_note' => 'Les règles restent sur cet appareil. Elles ne sont pas partagées avec vos autres appareils.',

    'reapply' => 'Réappliquer les règles à l\'historique',
    'reapplying' => 'Réapplication…',
    'new_rule' => 'Nouvelle règle',

    'reapply_progress_lead' => 'Réapplication des règles…',
    'reapply_progress_of' => 'sur',
    'reapply_progress_trail' => 'transactions vérifiées',

    'empty_heading' => 'Aucune règle pour l\'instant',
    'empty_body' => 'Les règles comparent les transactions sur plusieurs conditions et appliquent automatiquement les changements de catégorie, de tiers, de note et de marquage fiscal — à l\'import, et chaque fois que tu les réappliques à ton historique existant.',
    'empty_cta' => 'Créer ta première règle',

    'col_priority' => 'Priorité',
    'col_conditions' => 'Conditions',
    'col_actions' => 'Actions',
    'col_hits' => 'Correspondances',
    'col_created' => 'Créée le',
    'col_row_actions' => 'Actions',

    'more_conditions' => '+:count de plus',

    'delete_confirm' => 'Supprimer ?',
    'delete_yes' => 'Oui, supprimer',
    'cancel' => 'Annuler',
    'edit' => 'Modifier',
    'delete' => 'Supprimer',
    'edit_aria' => 'Modifier la règle (priorité :priority)',
    'delete_aria' => 'Supprimer la règle (priorité :priority)',

    'footer_note' => 'Les règles et l\'historique des commerçants fonctionnent ensemble. Supprimer une règle n\'efface pas ce que Beatrax a appris de tes catégorisations passées — le prochain import peut toujours suggérer la même catégorie à partir de l\'historique.',

    'chip_category' => 'Catégorie : :path',
    'chip_counterparty' => 'Tiers : :path',
    'chip_note' => 'Note',
    'chip_tax_tag' => 'Marquage fiscal',

    'flash_deleted' => 'Règle supprimée.',
    'flash_not_found' => 'Règle introuvable (elle a peut-être été supprimée dans un autre onglet).',
    'flash_saved' => 'Règle enregistrée.',
    'flash_reapplying' => 'Réapplication des règles à ton historique…',
    'summary_no_changes' => 'Aucun changement — ton historique correspond déjà à tes règles.',
    'summary_updated' => ':fields champs mis à jour sur :transactions transactions.',
    'summary_reconciled_skipped' => ':count transactions rapprochées ont été ignorées.',
];
