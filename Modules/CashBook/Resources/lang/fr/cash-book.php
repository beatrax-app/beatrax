<?php

declare(strict_types=1);

return [
    'page_title' => 'Livre de caisse',
    'heading' => 'Livre de caisse',
    'intro' => 'Enregistre à la main les dépenses en espèces et les autres dépenses hors banque. Les saisies manuelles rejoignent le même registre que tes imports — elles sont catégorisées, rattachées à un tiers, analysées pour la récurrence et comptent dans ton mois.',

    'direction' => 'Sens',
    'expense' => 'Dépense',
    'income' => 'Revenu',

    'amount' => 'Montant (:symbol)',
    'date' => 'Date',
    'counterparty' => 'Tiers',
    'counterparty_placeholder' => 'ex. Boulangerie',
    'category' => 'Catégorie',
    'optional' => '(facultatif)',
    'uncategorized' => 'Non catégorisé',
    'note' => 'Note',

    'add_entry' => 'Ajouter une saisie',
    'manual_entries' => 'Saisies manuelles',
    'no_entries' => 'Aucune saisie manuelle pour l\'instant.',
    'delete_entry' => 'Supprimer la saisie',
    'delete_entry_caption' => 'Supprimer',
    'delete' => 'Supprimer',
    'delete_confirm' => 'Supprimer cette écriture ?',
    'delete_keep' => 'Conserver',

    'errors' => [
        'amount_positive' => 'Saisis un montant supérieur à zéro.',
        'amount_too_large' => 'Ce montant est trop élevé. Vérifie les chiffres.',
        'amount_unreadable' => 'Le montant n’a pas pu être lu. Saisis-le avec au maximum :decimals décimale, par exemple :example.|Le montant n’a pas pu être lu. Saisis-le avec au maximum :decimals décimales, par exemple :example.',
        'amount_unreadable_whole' => 'Le montant n’a pas pu être lu. Cette devise n’a pas de décimales, saisis donc un nombre entier, par exemple :example.',
        'invalid_date' => 'Saisis une date valide.',
        'not_recorded' => 'L’écriture n’a pas été enregistrée. Essaie de l’ajouter à nouveau.',
    ],

    'toast' => [
        'added' => 'Saisie en espèces ajoutée.',
        'removed' => 'Saisie en espèces supprimée.',
        'reconciled_locked' => 'Cette transaction est rapprochée. Annule le rapprochement pour la modifier.',
    ],
];
