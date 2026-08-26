<?php

declare(strict_types=1);

return [
    'page_title' => 'Triage des tiers',
    'heading' => 'Trier les tiers inconnus',

    'progress' => ':seen sur :total · :percent % · ~:minutes min restantes',
    'progress_aria' => 'Progression du triage',

    'all_caught_aria' => 'Tous les tiers sont étiquetés',
    'all_caught_heading' => '🎉 Tout est à jour — chaque tiers est étiqueté.',
    'back_to_index' => 'Retour aux tiers →',

    'meta' => ':count transaction · dernière apparition le :date|:count transactions · dernière apparition le :date',

    'suggested_aria' => 'Correspondance suggérée',
    'suggestion_medium' => '✨ Peut-être **:name** — confiance moyenne',
    'suggestion_low' => 'Correspondance de motif : **:name** — confiance faible. Vérifie avant de lier.',
    'suggestion_high' => '✨ On dirait **:name** — confiance élevée',

    'reasoning' => ':hits sur :total opération récente sur cet IBAN correspond à :name.|:hits sur :total opérations récentes sur cet IBAN correspondent à :name.',
    'yes_link' => 'Oui, lier à :name ↵',
    'no_not' => 'Non, pas :name',

    'recent_on_iban' => 'Transactions récentes sur cet IBAN',
    'recent_on_counterparty' => 'Transactions récentes avec cette contrepartie',
    'no_transactions_yet' => 'Aucune transaction enregistrée pour l\'instant.',

    'label_manually' => 'Ou étiqueter manuellement',
    'display_name_label' => 'Nom affiché',
    'display_name_placeholder' => 'Nom affiché…',
    'type_label' => 'Type',
    'type_merchant' => 'Commerçant',
    'type_personal' => 'Particulier',
    'type_bank' => 'Banque',
    'type_government' => 'Administration',
    'save_label' => 'Enregistrer l\'étiquette',

    'skip' => 'Passer pour l\'instant',
    'mark_ignored' => 'Marquer comme ignoré',
    'previous' => 'Inconnu précédent',
    'next' => 'Suivant',

    'kbd_yes' => 'oui',
    'kbd_no' => 'non',
    'kbd_skip' => 'passer',
    'kbd_next' => 'suivant',

    'footer' => ':seen déjà étiquetés · :count restants',
];
