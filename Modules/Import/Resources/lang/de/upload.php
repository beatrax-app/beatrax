<?php

declare(strict_types=1);

return [
    'page_title' => 'Kontoauszug hochladen',
    'heading' => 'Kontoauszug hochladen',
    'migrate_prompt' => 'Wechselst du von einer anderen Budget-App?',
    'migrate_link' => 'Aus YNAB oder Actual importieren',
    'subtitle' => 'Zieh einen Bank-, Karten- oder PayPal-Export hierher, oder eine E-Mail-Belegdatei.',
    'mime_hint' => 'Diese Datei sieht nicht nach einem unterstützten Kontoauszugs-Export aus. Nimm eine Bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, eine Kartenabrechnung als PDF, eine E-Mail-Nachricht (.eml) oder ein Postfach-Archiv (.mbox).',

    'source_label' => 'Quelle',

    'issuer_other_bank' => 'Andere Bank (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-Mail-Datei (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Datei',
    'submit' => 'Kontoauszug hochladen',

    'formats' => [
        'activity_download' => 'Aktivitätsübersicht (CSV)',
        'email_message' => 'E-Mail-Nachricht (.eml)',
        'mailbox_archive' => 'Postfach-Archiv (.mbox)',
        'ing_nl' => 'ING Niederlande (CSV)',
    ],

    'errors' => [
        'file_max' => 'Diese Datei ist zu groß. Nimm einen Kontoauszugs-Export, der unter der Größenbegrenzung des gewählten Formats bleibt.',
        'file_extensions' => 'Diese Datei sieht nicht nach einem unterstützten Kontoauszugs-Export aus. Nimm eine Bank-CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, eine Kartenabrechnung als PDF, eine E-Mail-Nachricht (.eml) oder ein Postfach-Archiv (.mbox).',
        'issuer_format' => 'Der Wert :attribute ist für die Quelle :source nicht gültig.',
        'process_failed' => 'Diese Datei konnte nicht verarbeitet werden (:class). Der vollständige Fehler steht in /dev/logs.',
    ],
];
