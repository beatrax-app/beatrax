<?php

declare(strict_types=1);

return [
    'page_title' => 'Učitaj izvod',
    'heading' => 'Učitaj izvod',
    'migrate_prompt' => 'Prelaziš s druge aplikacije za proračun?',
    'migrate_link' => 'Uvezi iz YNAB-a ili Actuala',
    'subtitle' => 'Ubaci izvoz iz banke, s kartice ili PayPala, ili datoteku s potvrdom iz e-pošte.',
    'mime_hint' => 'Ta datoteka ne izgleda kao podržani izvoz izvoda. Ubaci bankovni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s kartičnim izvodom, poruku e-pošte (.eml) ili arhivu pretinca e-pošte (.mbox).',

    'source_label' => 'Izvor',

    'issuer_other_bank' => 'Druga banka (N26, Revolut, ING…)',
    'issuer_email_file' => 'Datoteka e-pošte (.eml, .mbox)',

    'format_label' => 'Format',
    'file_label' => 'Datoteka',
    'submit' => 'Učitaj izvod',

    'formats' => [
        'activity_download' => 'Preuzimanje aktivnosti (CSV)',
        'email_message' => 'Poruka e-pošte (.eml)',
        'mailbox_archive' => 'Arhiva pretinca e-pošte (.mbox)',
        'ing_nl' => 'ING Nizozemska (CSV)',
    ],

    'errors' => [
        'file_max' => 'Ta datoteka je prevelika. Ubaci izvoz izvoda unutar ograničenja veličine za odabrani format.',
        'file_extensions' => 'Ta datoteka ne izgleda kao podržani izvoz izvoda. Ubaci bankovni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s kartičnim izvodom, poruku e-pošte (.eml) ili arhivu pretinca e-pošte (.mbox).',
        'issuer_format' => 'Vrijednost :attribute nije valjana za izvor :source.',
        'process_failed' => 'Ovu datoteku nije moguće obraditi (:class). Cijela pogreška nalazi se u /dev/logs.',
    ],
];
