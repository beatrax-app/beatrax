<?php

declare(strict_types=1);

return [
    'page_title' => 'Aperçu de l\'import',
    'heading' => 'Aperçu de l\'import',
    'discard' => 'Abandonner l\'import',
    'confirm' => 'Confirmer l\'import',
    'subtitle' => 'Vérifie les lignes analysées. Rien n\'est enregistré dans ton registre tant que tu n\'as pas confirmé.',

    'already_imported' => 'Ce fichier a déjà été importé.',

    'already_imported_link' => 'Voir le résultat de l\'import',

    'expired_html' => 'L\'aperçu a expiré. <a href="/imports/new" class="underline">Téléverse à nouveau le fichier</a> pour réessayer.',

    'save_name' => 'Enregistrer le nom',
    'account_name_label' => 'Nom du compte',
    'account_placeholder' => 'ex. Compte épargne principal',
    'rename_aria' => 'Renommer ce tiers',

    'unknown_iban_prefix' => 'Nous avons trouvé un IBAN inconnu :',

    'unknown_account_prefix' => 'Nous avons trouvé un compte inconnu :',
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
        'failed_detail' => 'les détails sont dans le journal des tâches',
        'open_horizon' => 'Ouvre Horizon',
        'failed_suffix' => 'pour réessayer ou inspecter.',
    ],

    'rows_shown' => 'Lignes affichées : :shown sur :total',

    'show_more' => 'Afficher plus de lignes',

    'errors' => [
        'app_locked' => 'Déverrouillez l\'application pour importer : les clés de chiffrement ne peuvent pas être utilisées tant qu\'elle est verrouillée.',
        'file_stopped_short' => 'La ligne d\'en-tête correspondait, le format est donc le bon. La lecture s\'est arrêtée avant la fin du fichier. Une seule ligne illisible suffit, tout comme un fichier trop volumineux pour cet appareil. Essaie une période plus courte.',
        'file_unreadable' => 'Ce fichier n\'a pas pu être lu.',
        'iban_not_in_preview' => 'Cet IBAN ne fait pas partie de l\'aperçu actuel.',
        'pdf_reader_unavailable' => 'Les relevés PDF ont besoin du programme pdftotext, qui n\'est pas installé ici. Importe ce fichier sur un ordinateur qui l\'a, ou utilise plutôt un export CSV de ta banque.',
        'row_unreadable' => 'Cette ligne n\'a pas pu être lue.',
        'unknown_account' => 'Cette ligne appartient à un compte auquel tu n\'as pas encore donné de nom.',
    ],

    'failed' => [
        'heading' => 'Ce fichier n\'a pas pu être lu',
        'no_rows' => 'Aucune transaction n\'a été trouvée dans ce fichier, il n\'y a donc rien à importer.',
        'nothing_read' => 'Rien dans ce fichier n\'a pu être lu comme une transaction, il n\'y a donc rien à importer.',
        'every_row' => 'Aucune ligne de ce fichier n\'a pu être lue, il n\'y a donc rien à importer. Chacune est listée ci-dessous avec sa raison.',
        'likely_cause' => 'Le plus souvent, la ligne d\'en-tête ne correspond pas à la source choisie. Vérifie la banque et le format sur l\'écran de téléversement, ou télécharge à nouveau le relevé auprès de ta banque.',
        'truncated_heading' => 'Seule une partie de ce fichier a pu être lue',
        'truncated' => 'La lecture s\'est arrêtée au milieu du fichier. Tout ce qui suit n\'a pas été lu et ne sera pas importé.',
        'some_rows' => 'Certaines lignes n\'ont pas pu être lues. Elles sont signalées ci-dessous et seront ignorées ; confirmer importe les autres.',
        'detail_label' => 'Ce que l\'analyseur a signalé :',
        'rows_read_label' => 'Lignes lues',
        'rows_skipped_label' => 'Lignes ignorées',
    ],
];
