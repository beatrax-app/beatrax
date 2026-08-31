<?php

declare(strict_types=1);

return [
    'page_title' => 'Nahrát výpis z účtu',
    'heading' => 'Nahrát výpis z účtu',
    'migrate_prompt' => 'Přecházíš z jiné rozpočtové aplikace?',
    'migrate_link' => 'Import z YNAB nebo Actual',
    'subtitle' => 'Vlož výpis ve formátu CSV, CAMT.053, MT940 nebo PDF, případně soubor s účtenkou z e-mailu.',
    'mime_hint' => 'Podporované soubory: bankovní CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF výpisu z karty, e-mailová zpráva (.eml) nebo archiv schránky (.mbox).',

    'type_label' => 'Typ importu',

    'types' => [
        'csv' => 'Soubor CSV',
        'camt053' => 'Výpis CAMT.053 (XML)',
        'mt940' => 'Výpis MT940',
        'pdf' => 'Výpis z karty (PDF)',
        'email' => 'Soubor s účtenkou z e-mailu',
    ],

    'format_label' => 'Formát',

    'format_from_file' => 'Formát byl nastaven na :format, aby odpovídal vybranému souboru. Změň ho, pokud to není správně.',
    'file_label' => 'Soubor',
    'submit' => 'Nahrát výpis z účtu',

    'formats' => [
        'activity_download' => 'Přehled aktivity (CSV)',
        'email_message' => 'E-mailová zpráva (.eml)',
        'mailbox_archive' => 'Archiv schránky (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Tenhle soubor je příliš velký. Vlož export výpisu, který se vejde do limitu velikosti pro zvolený formát.',
        'file_extensions' => 'Tenhle soubor nevypadá jako podporovaný export výpisu. Vlož bankovní CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s výpisem z karty, e-mailovou zprávu (.eml) nebo archiv schránky (.mbox).',
        'type_format' => 'Hodnota pole :attribute není platná pro typ importu :type.',
        'process_failed' => 'Tenhle soubor se nepodařilo zpracovat (:class). Celou chybu najdeš v /dev/logs.',
    ],
];
