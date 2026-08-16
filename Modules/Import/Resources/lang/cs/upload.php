<?php

declare(strict_types=1);

return [
    'page_title' => 'Nahrát výpis z účtu',
    'heading' => 'Nahrát výpis z účtu',
    'migrate_prompt' => 'Přecházíš z jiné rozpočtové aplikace?',
    'migrate_link' => 'Import z YNAB nebo Actual',
    'subtitle' => 'Vlož export z banky, z karty nebo z účtu PayPal, případně soubor s účtenkou z e-mailu.',
    'mime_hint' => 'Tenhle soubor nevypadá jako podporovaný export výpisu. Vlož bankovní CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s výpisem z karty, e-mailovou zprávu (.eml) nebo archiv schránky (.mbox).',

    'source_label' => 'Zdroj',

    'issuer_other_bank' => 'Jiná banka (N26, Revolut, ING…)',
    'issuer_email_file' => 'Soubor e-mailu (.eml, .mbox)',

    'format_label' => 'Formát',
    'file_label' => 'Soubor',
    'submit' => 'Nahrát výpis z účtu',

    'formats' => [
        'activity_download' => 'Přehled aktivity (CSV)',
        'email_message' => 'E-mailová zpráva (.eml)',
        'mailbox_archive' => 'Archiv schránky (.mbox)',
        'ing_nl' => 'ING Nizozemsko (CSV)',
    ],

    'errors' => [
        'file_max' => 'Tenhle soubor je příliš velký. Vlož export výpisu, který se vejde do limitu velikosti pro zvolený formát.',
        'file_extensions' => 'Tenhle soubor nevypadá jako podporovaný export výpisu. Vlož bankovní CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s výpisem z karty, e-mailovou zprávu (.eml) nebo archiv schránky (.mbox).',
        'issuer_format' => 'Hodnota pole :attribute není platná pro zdroj :source.',
        'process_failed' => 'Tenhle soubor se nepodařilo zpracovat (:class). Celou chybu najdeš v /dev/logs.',
    ],
];
