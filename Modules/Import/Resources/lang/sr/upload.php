<?php

declare(strict_types=1);

return [
    'page_title' => 'Otpremi izvod',
    'heading' => 'Otpremi izvod',
    'migrate_prompt' => 'Prelaziš sa druge aplikacije za budžet?',
    'migrate_link' => 'Uvezi iz YNAB-a ili Actuala',
    'subtitle' => 'Ubaci izvoz iz banke, sa kartice ili PayPala, ili datoteku sa potvrdom iz e-pošte.',
    'mime_hint' => 'Ta datoteka ne izgleda kao podržan izvoz izvoda. Ubaci bankarski CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF sa kartičnim izvodom, poruku e-pošte (.eml) ili arhivu prijemnog sandučeta (.mbox).',

    'source_label' => 'Izvor',

    'issuer_other_bank' => 'Druga banka (N26, Revolut, ING…)',
    'issuer_email_file' => 'Datoteka e-pošte (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Datoteka',
    'submit' => 'Otpremi izvod',

    'formats' => [
        'activity_download' => 'Preuzimanje aktivnosti (CSV)',
        'email_message' => 'Poruka e-pošte (.eml)',
        'mailbox_archive' => 'Arhiva prijemnog sandučeta (.mbox)',
        'ing_nl' => 'ING Holandija (CSV)',
    ],

    'errors' => [
        'file_max' => 'Ta datoteka je prevelika. Ubaci izvoz izvoda u okviru ograničenja veličine za izabrani format.',
        'file_extensions' => 'Ta datoteka ne izgleda kao podržan izvoz izvoda. Ubaci bankarski CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF sa kartičnim izvodom, poruku e-pošte (.eml) ili arhivu prijemnog sandučeta (.mbox).',
        'issuer_format' => 'Vrednost :attribute nije važeća za izvor :source.',
        'process_failed' => 'Ovu datoteku nije moguće obraditi (:class). Cela greška nalazi se u /dev/logs.',
    ],
];
