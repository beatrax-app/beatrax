<?php

declare(strict_types=1);

return [
    'page_title' => 'Učitaj izvod',
    'heading' => 'Učitaj izvod',
    'migrate_prompt' => 'Prelaziš s druge aplikacije za proračun?',
    'migrate_link' => 'Uvezi iz YNAB-a ili Actuala',
    'subtitle' => 'Ubaci izvod u formatu CSV, CAMT.053, MT940 ili PDF, ili datoteku s potvrdom iz e-pošte.',
    'mime_hint' => 'Podržane datoteke: bankovni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF izvatka kartice, poruka e-pošte (.eml) ili arhiva poštanskog sandučića (.mbox).',

    'type_label' => 'Vrsta uvoza',

    'types' => [
        'csv' => 'CSV datoteka',
        'camt053' => 'Izvod CAMT.053 (XML)',
        'mt940' => 'Izvod MT940',
        'pdf' => 'Kartični izvod (PDF)',
        'email' => 'Datoteka s potvrdom iz e-pošte',
    ],

    'format_label' => 'Format',

    'format_from_file' => 'Format je postavljen na :format kako bi odgovarao datoteci koju si odabrao. Promijeni ga ako to nije točno.',
    'file_label' => 'Datoteka',
    'submit' => 'Učitaj izvod',

    'formats' => [
        'activity_download' => 'Preuzimanje aktivnosti (CSV)',
        'email_message' => 'Poruka e-pošte (.eml)',
        'mailbox_archive' => 'Arhiva pretinca e-pošte (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Ta datoteka je prevelika. Ubaci izvoz izvoda unutar ograničenja veličine za odabrani format.',
        'file_extensions' => 'Ta datoteka ne izgleda kao podržani izvoz izvoda. Ubaci bankovni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s kartičnim izvodom, poruku e-pošte (.eml) ili arhivu pretinca e-pošte (.mbox).',
        'type_format' => 'Vrijednost :attribute nije valjana za vrstu uvoza :type.',
        'process_failed' => 'Ovu datoteku nije moguće obraditi (:class). Cijela pogreška nalazi se u /dev/logs.',
    ],
];
