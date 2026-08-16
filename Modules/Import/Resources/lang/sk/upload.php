<?php

declare(strict_types=1);

return [
    'page_title' => 'Nahrať výpis z účtu',
    'heading' => 'Nahrať výpis z účtu',
    'migrate_prompt' => 'Prechádzaš z inej rozpočtovej aplikácie?',
    'migrate_link' => 'Importovať z YNAB alebo Actual',
    'subtitle' => 'Vlož export z banky, karty či PayPalu alebo súbor s e-mailovou účtenkou.',
    'mime_hint' => 'Tento súbor nevyzerá ako podporovaný export výpisu. Vlož bankový CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s výpisom karty, e-mailovú správu (.eml) alebo archív schránky (.mbox).',

    'source_label' => 'Zdroj',

    'issuer_other_bank' => 'Iná banka (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-mailový súbor (.eml, .mbox)',

    'format_label' => 'Formát',
    'file_label' => 'Súbor',
    'submit' => 'Nahrať výpis',

    'formats' => [
        'activity_download' => 'Prehľad aktivity (CSV)',
        'email_message' => 'E-mailová správa (.eml)',
        'mailbox_archive' => 'Archív schránky (.mbox)',
        'ing_nl' => 'ING Holandsko (CSV)',
    ],

    'errors' => [
        'file_max' => 'Tento súbor je príliš veľký. Vlož export výpisu do veľkostného limitu zvoleného formátu.',
        'file_extensions' => 'Tento súbor nevyzerá ako podporovaný export výpisu. Vlož bankový CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s výpisom karty, e-mailovú správu (.eml) alebo archív schránky (.mbox).',
        'issuer_format' => 'Hodnota :attribute nie je platná pre zdroj :source.',
        'process_failed' => 'Tento súbor sa nepodarilo spracovať (:class). Úplná chyba je v /dev/logs.',
    ],
];
