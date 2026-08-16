<?php

declare(strict_types=1);

return [
    'page_title' => 'Augšupielādēt konta izrakstu',
    'heading' => 'Augšupielādēt konta izrakstu',
    'migrate_prompt' => 'Pārejiet no citas budžeta lietotnes?',
    'migrate_link' => 'Importēt no YNAB vai Actual',
    'subtitle' => 'Ievelciet bankas, kartes vai PayPal eksportu vai e-pasta čeka failu.',
    'mime_hint' => 'Šis fails neizskatās pēc atbalstīta konta izraksta eksporta. Ievelciet bankas CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kartes izraksta PDF, e-pasta ziņojumu (.eml) vai pastkastes arhīvu (.mbox).',

    'source_label' => 'Avots',

    'issuer_other_bank' => 'Cita banka (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-pasta fails (.eml, .mbox)',

    'format_label' => 'Formāts',
    'file_label' => 'Fails',
    'submit' => 'Augšupielādēt konta izrakstu',

    'formats' => [
        'activity_download' => 'Activity Download (CSV)',
        'email_message' => 'E-pasta ziņojums (.eml)',
        'mailbox_archive' => 'Pastkastes arhīvs (.mbox)',
        'ing_nl' => 'ING Nīderlande (CSV)',
    ],

    'errors' => [
        'file_max' => 'Šis fails ir pārāk liels. Ievelciet konta izraksta eksportu, kas nepārsniedz izvēlētā formāta izmēra ierobežojumu.',
        'file_extensions' => 'Šis fails neizskatās pēc atbalstīta konta izraksta eksporta. Ievelciet bankas CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, kartes izraksta PDF, e-pasta ziņojumu (.eml) vai pastkastes arhīvu (.mbox).',
        'issuer_format' => 'Vērtība :attribute nav derīga avotam :source.',
        'process_failed' => 'Neizdevās apstrādāt šo failu (:class). Pilns kļūdas apraksts ir /dev/logs.',
    ],
];
