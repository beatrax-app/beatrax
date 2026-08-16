<?php

declare(strict_types=1);

return [
    'page_title' => 'Aperçu de l\'import',
    'heading' => 'Aperçu de l\'import',
    'discard' => 'Abandonner l\'import',
    'confirm' => 'Confirmer l\'import',
    'subtitle' => 'Vérifie les lignes analysées. Rien n\'est enregistré dans ton registre tant que tu n\'as pas confirmé.',

    'expired_html' => 'L\'aperçu a expiré. <a href="/imports/new" class="underline">Téléverse à nouveau le fichier</a> pour réessayer.',

    'save_name' => 'Enregistrer le nom',
    'account_name_label' => 'Nom du compte',
    'account_placeholder' => 'ex. Compte épargne principal',
    'rename_aria' => 'Renommer ce tiers',

    'unknown_iban_prefix' => 'Nous avons trouvé un IBAN inconnu :',
    'unknown_iban_suffix' => 'Donne un nom à ce compte.',

    'ics' => [
        'heading' => 'Donne un nom à ton compte carte ICS.',
        'help' => 'C\'est la première fois que tu importes des données ICS. Donne un nom à cette carte pour qu\'elle apparaisse de la même façon partout dans l\'app.',
        'placeholder' => 'ex. Carte ICS',
    ],

    'paypal' => [
        'heading' => 'Donne un nom à ton compte PayPal.',
        'help' => 'C\'est la première fois que tu importes des données PayPal. Donne un nom à ce portefeuille pour qu\'il apparaisse de la même façon partout dans l\'app.',
        'placeholder' => 'ex. PayPal',
    ],

    'col_date' => 'Date',
    'col_funding_source' => 'Source de financement',
    'col_counterparty' => 'Tiers',
    'col_amount' => 'Montant',
    'col_status' => 'Statut',

    'status' => [
        'new' => 'Nouvelle',
        'new_title' => 'Sera ajoutée à ton registre.',
        'duplicate' => 'Doublon',
        'duplicate_title' => 'Déjà importée — sera ignorée.',
        'enriched' => 'Enrichie',
        'enriched_title' => 'La ligne existante sera mise à jour avec une référence source plus fiable.',
        'error' => 'Erreur',
    ],

    'chain' => [
        'heading' => 'Résolution des chaînes…',
        'pending' => 'En file d\'attente. Le résolveur de chaînes va démarrer sous peu.',
        'running' => 'Liaison des chaînes de financement et décomposition des règlements du relevé.',
        'failed_prefix' => 'La résolution des chaînes a échoué :',
        'unknown_error' => 'une erreur inconnue est survenue',
        'open_horizon' => 'Ouvre Horizon',
        'failed_suffix' => 'pour réessayer ou inspecter.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Cet IBAN ne fait pas partie de l\'aperçu actuel.',
    ],
];
