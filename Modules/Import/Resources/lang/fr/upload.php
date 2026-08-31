<?php

declare(strict_types=1);

return [
    'page_title' => 'Importer un relevé',
    'heading' => 'Importer un relevé',
    'migrate_prompt' => 'Tu viens d\'une autre application de budget ?',
    'migrate_link' => 'Importer depuis YNAB ou Actual',
    'subtitle' => 'Dépose un relevé au format CSV, CAMT.053, MT940 ou PDF, ou un fichier de reçu par e-mail.',
    'mime_hint' => 'Fichiers pris en charge : CSV bancaire, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF de relevé de carte, message e-mail (.eml) ou archive de boîte aux lettres (.mbox).',

    'type_label' => 'Type d\'import',

    'types' => [
        'csv' => 'Fichier CSV',
        'camt053' => 'Relevé CAMT.053 (XML)',
        'mt940' => 'Relevé MT940',
        'pdf' => 'Relevé de carte (PDF)',
        'email' => 'Fichier de reçu par e-mail',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Le format a été réglé sur :format pour correspondre au fichier que tu as choisi. Change-le si ce n\'est pas le bon.',
    'file_label' => 'Fichier',
    'submit' => 'Importer le relevé',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'Message e-mail (.eml)',
        'mailbox_archive' => 'Archive de boîte aux lettres (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Ce fichier est trop volumineux. Dépose un export de relevé sous la limite de taille du format choisi.',
        'file_extensions' => 'Ce fichier ne ressemble pas à un export de relevé pris en charge. Dépose un CSV bancaire, un MT940 (.sta / .mt940 / .txt), un XML CAMT.053, un PDF de relevé de carte, un message e-mail (.eml) ou une archive de boîte aux lettres (.mbox).',
        'type_format' => 'La valeur :attribute n\'est pas valide pour le type d\'import :type.',
        'process_failed' => 'Impossible de traiter ce fichier (:class). L\'erreur complète est dans /dev/logs.',
    ],
];
