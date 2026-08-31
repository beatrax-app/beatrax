<?php

declare(strict_types=1);

return [
    'page_title' => 'Kontoauszug hochladen',
    'heading' => 'Kontoauszug hochladen',
    'migrate_prompt' => 'Wechselst du von einer anderen Budget-App?',
    'migrate_link' => 'Aus YNAB oder Actual importieren',
    'subtitle' => 'Zieh einen Kontoauszug als CSV, CAMT.053, MT940 oder PDF hierher, oder eine E-Mail-Belegdatei.',
    'mime_hint' => 'Unterstützte Dateien: Bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, Kartenabrechnungs-PDF, E-Mail-Nachricht (.eml) oder Mailbox-Archiv (.mbox).',

    'type_label' => 'Importtyp',

    'types' => [
        'csv' => 'CSV-Datei',
        'camt053' => 'CAMT.053-Kontoauszug (XML)',
        'mt940' => 'MT940-Kontoauszug',
        'pdf' => 'Kartenabrechnung (PDF)',
        'email' => 'E-Mail-Belegdatei',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Das Format wurde auf :format gesetzt, passend zur gewählten Datei. Ändern Sie es, wenn das nicht stimmt.',
    'file_label' => 'Datei',
    'submit' => 'Kontoauszug hochladen',

    'formats' => [
        'activity_download' => 'Aktivitätsübersicht (CSV)',
        'email_message' => 'E-Mail-Nachricht (.eml)',
        'mailbox_archive' => 'Postfach-Archiv (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Diese Datei ist zu groß. Nimm einen Kontoauszugs-Export, der unter der Größenbegrenzung des gewählten Formats bleibt.',
        'file_extensions' => 'Diese Datei sieht nicht nach einem unterstützten Kontoauszugs-Export aus. Nimm eine Bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, eine Kartenabrechnung als PDF, eine E-Mail-Nachricht (.eml) oder ein Postfach-Archiv (.mbox).',
        'type_format' => 'Der Wert :attribute ist für den Importtyp :type nicht gültig.',
        'process_failed' => 'Diese Datei konnte nicht verarbeitet werden (:class). Der vollständige Fehler steht in /dev/logs.',
    ],
];
