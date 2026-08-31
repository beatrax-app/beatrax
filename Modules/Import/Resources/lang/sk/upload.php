<?php

declare(strict_types=1);

return [
    'page_title' => 'Nahrať výpis z účtu',
    'heading' => 'Nahrať výpis z účtu',
    'migrate_prompt' => 'Prechádzaš z inej rozpočtovej aplikácie?',
    'migrate_link' => 'Importovať z YNAB alebo Actual',
    'subtitle' => 'Vlož výpis vo formáte CSV, CAMT.053, MT940 alebo PDF alebo súbor s e-mailovou účtenkou.',
    'mime_hint' => 'Podporované súbory: bankový CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF výpisu z karty, e-mailová správa (.eml) alebo archív schránky (.mbox).',

    'type_label' => 'Typ importu',

    'types' => [
        'csv' => 'Súbor CSV',
        'camt053' => 'Výpis CAMT.053 (XML)',
        'mt940' => 'Výpis MT940',
        'pdf' => 'Výpis z karty (PDF)',
        'email' => 'Súbor s e-mailovou účtenkou',
    ],

    'format_label' => 'Formát',

    'format_from_file' => 'Formát bol nastavený na :format, aby zodpovedal vybranému súboru. Zmeň ho, ak to tak nie je.',
    'file_label' => 'Súbor',
    'submit' => 'Nahrať výpis',

    'formats' => [
        'activity_download' => 'Prehľad aktivity (CSV)',
        'email_message' => 'E-mailová správa (.eml)',
        'mailbox_archive' => 'Archív schránky (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Tento súbor je príliš veľký. Vlož export výpisu do veľkostného limitu zvoleného formátu.',
        'file_extensions' => 'Tento súbor nevyzerá ako podporovaný export výpisu. Vlož bankový CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s výpisom karty, e-mailovú správu (.eml) alebo archív schránky (.mbox).',
        'type_format' => 'Hodnota :attribute nie je platná pre typ importu :type.',
        'process_failed' => 'Tento súbor sa nepodarilo spracovať (:class). Úplná chyba je v /dev/logs.',
    ],
];
