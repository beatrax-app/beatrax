<?php

declare(strict_types=1);

return [
    'page_title' => 'Încarcă extrasul de cont',
    'heading' => 'Încarcă extrasul de cont',
    'migrate_prompt' => 'Vii de la altă aplicație de bugetare?',
    'migrate_link' => 'Importă din YNAB sau Actual',
    'subtitle' => 'Trage aici un export bancar, de card sau PayPal, ori un fișier cu bon primit pe e-mail.',
    'mime_hint' => 'Fișierul nu pare un export de extras acceptat. Trage aici un CSV bancar, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, un PDF cu extrasul cardului, un mesaj de e-mail (.eml) sau o arhivă de căsuță poștală (.mbox).',

    'source_label' => 'Sursă',

    'issuer_other_bank' => 'Altă bancă (N26, Revolut, ING…)',
    'issuer_email_file' => 'Fișier de e-mail (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Fișier',
    'submit' => 'Încarcă extrasul de cont',

    'formats' => [
        'activity_download' => 'Descărcare de activitate (CSV)',
        'email_message' => 'Mesaj de e-mail (.eml)',
        'mailbox_archive' => 'Arhivă de căsuță poștală (.mbox)',
        'ing_nl' => 'ING Țările de Jos (CSV)',
    ],

    'errors' => [
        'file_max' => 'Fișierul este prea mare. Trage aici un export de extras care se încadrează în limita de mărime a formatului ales.',
        'file_extensions' => 'Fișierul nu pare un export de extras acceptat. Trage aici un CSV bancar, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, un PDF cu extrasul cardului, un mesaj de e-mail (.eml) sau o arhivă de căsuță poștală (.mbox).',
        'issuer_format' => 'Valoarea :attribute nu este validă pentru sursa :source.',
        'process_failed' => 'Acest fișier nu a putut fi procesat (:class). Eroarea completă se află în /dev/logs.',
    ],
];
