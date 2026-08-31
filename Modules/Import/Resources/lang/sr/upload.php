<?php

declare(strict_types=1);

return [
    'page_title' => 'Otpremi izvod',
    'heading' => 'Otpremi izvod',
    'migrate_prompt' => 'Prelaziš sa druge aplikacije za budžet?',
    'migrate_link' => 'Uvezi iz YNAB-a ili Actuala',
    'subtitle' => 'Ubaci izvod u formatu CSV, CAMT.053, MT940 ili PDF, ili datoteku sa potvrdom iz e-pošte.',
    'mime_hint' => 'Podržane datoteke: bankovni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF izvoda kartice, poruka e-pošte (.eml) ili arhiva poštanskog sandučeta (.mbox).',

    'type_label' => 'Vrsta uvoza',

    'types' => [
        'csv' => 'CSV datoteka',
        'camt053' => 'Izvod CAMT.053 (XML)',
        'mt940' => 'Izvod MT940',
        'pdf' => 'Kartični izvod (PDF)',
        'email' => 'Datoteka sa potvrdom iz e-pošte',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Format je postavljen na :format da odgovara datoteci koju si izabrao. Promeni ga ako to nije tačno.',
    'file_label' => 'Datoteka',
    'submit' => 'Otpremi izvod',

    'formats' => [
        'activity_download' => 'Preuzimanje aktivnosti (CSV)',
        'email_message' => 'Poruka e-pošte (.eml)',
        'mailbox_archive' => 'Arhiva prijemnog sandučeta (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Ta datoteka je prevelika. Ubaci izvoz izvoda u okviru ograničenja veličine za izabrani format.',
        'file_extensions' => 'Ta datoteka ne izgleda kao podržan izvoz izvoda. Ubaci bankarski CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF sa kartičnim izvodom, poruku e-pošte (.eml) ili arhivu prijemnog sandučeta (.mbox).',
        'type_format' => 'Vrednost :attribute nije važeća za vrstu uvoza :type.',
        'process_failed' => 'Ovu datoteku nije moguće obraditi (:class). Cela greška nalazi se u /dev/logs.',
    ],
];
