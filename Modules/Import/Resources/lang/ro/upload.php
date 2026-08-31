<?php

declare(strict_types=1);

return [
    'page_title' => 'Încarcă extrasul de cont',
    'heading' => 'Încarcă extrasul de cont',
    'migrate_prompt' => 'Vii de la altă aplicație de bugetare?',
    'migrate_link' => 'Importă din YNAB sau Actual',
    'subtitle' => 'Trage aici un extras în CSV, CAMT.053, MT940 sau PDF, ori un fișier cu bon primit pe e-mail.',
    'mime_hint' => 'Fișiere acceptate: CSV bancar, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF cu extrasul cardului, mesaj e-mail (.eml) sau arhivă de cutie poștală (.mbox).',

    'type_label' => 'Tip de import',

    'types' => [
        'csv' => 'Fișier CSV',
        'camt053' => 'Extras CAMT.053 (XML)',
        'mt940' => 'Extras MT940',
        'pdf' => 'Extras de card (PDF)',
        'email' => 'Fișier cu bon primit pe e-mail',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Formatul a fost setat pe :format ca să se potrivească cu fișierul ales. Schimbă-l dacă nu e corect.',
    'file_label' => 'Fișier',
    'submit' => 'Încarcă extrasul de cont',

    'formats' => [
        'activity_download' => 'Descărcare de activitate (CSV)',
        'email_message' => 'Mesaj de e-mail (.eml)',
        'mailbox_archive' => 'Arhivă de căsuță poștală (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Fișierul este prea mare. Trage aici un export de extras care se încadrează în limita de mărime a formatului ales.',
        'file_extensions' => 'Fișierul nu pare un export de extras acceptat. Trage aici un CSV bancar, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, un PDF cu extrasul cardului, un mesaj de e-mail (.eml) sau o arhivă de căsuță poștală (.mbox).',
        'type_format' => 'Valoarea :attribute nu este validă pentru tipul de import :type.',
        'process_failed' => 'Acest fișier nu a putut fi procesat (:class). Eroarea completă se află în /dev/logs.',
    ],
];
