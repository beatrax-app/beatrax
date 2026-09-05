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
    'unreadable_html' => 'L\'aperçu ne peut pas être lu. <a href="/imports/new" class="underline">Téléverse à nouveau le fichier</a> pour réessayer.',

    'save_name' => 'Enregistrer le nom',
    'account_name_label' => 'Nom du compte',
    'account_placeholder' => 'ex. Compte épargne principal',
    'rename_aria' => 'Renommer ce tiers',

    'unknown_iban_prefix' => 'Nous avons trouvé un IBAN inconnu :',

    'unknown_account_prefix' => 'Nous avons trouvé un compte inconnu :',
    'unknown_iban_suffix' => 'Donne un nom à ce compte.',

    'ics' => [
        'name' => 'Carte ICS',
        'heading' => 'Donne un nom à ton compte carte ICS.',
        'help' => 'C\'est la première fois que tu importes des données ICS. Donne un nom à cette carte pour qu\'elle apparaisse de la même façon partout dans l\'app.',
        'placeholder' => 'ex. Carte ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Donne un nom à ton compte PayPal.',
        'help' => 'C\'est la première fois que tu importes des données PayPal. Donne un nom à ce portefeuille pour qu\'il apparaisse de la même façon partout dans l\'app.',
        'placeholder' => 'ex. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Donne un nom à ton compte Google Play.',
        'help' => "C'est la première fois que tu importes un reçu Google Play. Donne un nom à ce compte pour qu'il apparaisse de la même façon partout dans l'app.",
        'placeholder' => 'ex. Google Play',
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

    'rows_shown' => 'Lignes affichées : :shown sur :total',

    'show_more' => 'Afficher plus de lignes',

    'errors' => [
        'app_locked' => 'Déverrouillez l\'application pour importer : les clés de chiffrement ne peuvent pas être utilisées tant qu\'elle est verrouillée.',
        'archive_holds_one_message' => 'Ce fichier est un seul message e-mail, pas une archive de boîte aux lettres ; lu comme une archive, il ne contient rien. Importe-le à nouveau avec le format Message e-mail.',
        'email_file_is_an_archive' => 'Ce fichier est une archive de boîte aux lettres : il contient plus d\'un message, et lu comme un seul message il n\'en prendrait que le premier. Importe-le à nouveau avec le format Archive de boîte aux lettres.',
        'file_stopped_short' => 'La ligne d\'en-tête correspondait, le format est donc le bon. La lecture s\'est arrêtée avant la fin du fichier. Une seule ligne illisible suffit, tout comme un fichier trop volumineux pour cet appareil. Essaie une période plus courte.',
        'file_unreadable' => 'Ce fichier n\'a pas pu être lu.',
        'file_unreadable_detail' => 'L\'application n\'a pas pu lire ce fichier (:code). Les détails complets figurent dans le journal ; citez ce code si vous signalez un problème.',
        'iban_not_in_preview' => 'Cet IBAN ne fait pas partie de l\'aperçu actuel.',
        'message_unreadable' => "Ce message n'a pas pu être lu, il a donc été ignoré.",
        'not_an_email_file' => 'Ce fichier n\'est ni un message e-mail ni une archive de boîte aux lettres, il n\'y a donc rien à y lire comme reçu. Choisis le type d\'import et le format qui correspondent à ton fichier.',
        'pdf_has_no_text_layer' => 'Ce PDF ne contient aucun texte — c\'est un scan ou une photo d\'un relevé, il n\'y a donc rien à y lire. Télécharge le relevé lui-même auprès de ta banque, ou utilise plutôt un export CSV.',
        'pdf_password_protected' => 'Ce PDF est protégé par un mot de passe, aucun lecteur ne peut donc l\'ouvrir. Enregistre une copie non protégée depuis ta visionneuse PDF et importe celle-là.',
        'pdf_reader_unavailable' => 'Cette version de l\'app n\'a aucun lecteur PDF, un relevé PDF ne peut donc pas être ouvert ici. Importe ce fichier sur un autre appareil, ou utilise plutôt un export CSV de ta banque.',
        'row_belongs_to_another_statement' => 'Cette ligne appartient à une transaction d\'un autre fichier de relevé. Importez aussi ce relevé : les deux sont lus ensemble.',
        'row_unreadable' => 'Cette ligne n\'a pas pu être lue.',
        'row_unreadable_detail' => 'L\'application n\'a pas pu lire cette ligne (:code). Les détails complets figurent dans le journal ; citez ce code si vous signalez un problème.',
        'unknown_account' => 'Cette ligne appartient à un compte auquel tu n\'as pas encore donné de nom.',
    ],

    'refused' => [
        'accounts_to_name' => 'Ce fichier attend que tu donnes un nom au compte auquel ses lignes appartiennent.',
        'file_did_not_read_in_full' => "Ce fichier n'a pas pu être lu jusqu'au bout.",
        'nothing_importable' => 'Rien dans ce fichier ne peut être importé.',
        'preview_expired' => "L'aperçu de ce fichier est trop ancien pour être enregistré maintenant. Téléverse-le à nouveau.",
    ],

    'receipts' => [
        'heading' => 'Ce fichier a été lu comme un e-mail',
        'saved' => 'Ce qu\'il contenait est listé ci-dessous, et chaque message a été conservé.',
        'none_imported' => 'Rien de tout cela n\'est devenu une transaction, donc rien n\'a été ajouté à ton registre.',
        'shown' => 'Messages affichés : :shown sur :total',
        'no_subject' => 'Sans objet',

        'state' => [
            'read' => 'Lu comme un paiement — confirme cet import pour l\'ajouter à ton registre.',
            'not_a_payment' => 'Ce n\'est pas un paiement. Ce message annonce quelque chose au lieu de confirmer un paiement.',
            'unreadable' => 'Conservé. L\'application lit les reçus de cet expéditeur, mais n\'a trouvé ni montant, ni commerçant, ni référence dans ce message.',
            'unknown_sender' => 'Conservé. L\'application ne lit pas les reçus de cet expéditeur, elle n\'a donc rien pris du message.',
        ],
    ],

    'failed' => [
        'heading' => 'Ce fichier n\'a pas pu être lu',
        'no_rows' => 'Aucune transaction n\'a été trouvée dans ce fichier, il n\'y a donc rien à importer.',
        'nothing_read' => 'Rien dans ce fichier n\'a pu être lu comme une transaction, il n\'y a donc rien à importer.',
        'every_row' => 'Aucune ligne de ce fichier n\'a pu être lue, il n\'y a donc rien à importer. Chacune est listée ci-dessous avec sa raison.',
        'likely_cause' => 'Le plus souvent, la ligne d\'en-tête ne correspond pas à la source choisie. Vérifie la banque et le format sur l\'écran de téléversement, ou télécharge à nouveau le relevé auprès de ta banque.',
        'truncated_heading' => 'Seule une partie de ce fichier a pu être lue',
        'truncated' => 'La lecture s\'est arrêtée au milieu du fichier. Ce fichier ne peut pas être importé : n\'enregistrer que la partie lue laisserait le reste de la période manquant, sans rien pour le signaler.',
        'truncated_action' => 'Téléversez à nouveau le fichier, ou téléchargez une nouvelle copie du relevé auprès de votre banque.',
        'some_rows' => 'Certaines lignes n\'ont pas pu être lues. Elles sont signalées ci-dessous et seront ignorées ; confirmer importe les autres.',
        'detail_label' => 'Ce que l\'analyseur a signalé :',
        'rows_read_label' => 'Lignes lues',
        'rows_skipped_label' => 'Lignes ignorées',
    ],
];
