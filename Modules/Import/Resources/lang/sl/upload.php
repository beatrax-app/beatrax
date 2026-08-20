<?php

declare(strict_types=1);

return [
    'page_title' => 'Naloži izpisek',
    'heading' => 'Naloži izpisek',
    'migrate_prompt' => 'Prehajaš iz druge aplikacije za proračun?',
    'migrate_link' => 'Uvozi iz YNAB ali Actual',
    'subtitle' => 'Spusti sem izvoz iz banke, kartice ali PayPala ali datoteko z e-poštnim potrdilom.',
    'mime_hint' => 'Podprte datoteke: bančni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF izpiska kartice, e-poštno sporočilo (.eml) ali arhiv nabiralnika (.mbox).',

    'source_label' => 'Vir',

    'issuer_other_bank' => 'Druga banka (N26, Revolut, ING…)',
    'issuer_email_file' => 'E-poštna datoteka (.eml, .mbox)',

    'format_label' => 'Oblika',
    'file_label' => 'Datoteka',
    'submit' => 'Naloži izpisek',

    'formats' => [
        'activity_download' => 'Prenos aktivnosti (CSV)',
        'email_message' => 'E-poštno sporočilo (.eml)',
        'mailbox_archive' => 'Arhiv nabiralnika (.mbox)',
        'ing_nl' => 'ING Nizozemska (CSV)',
    ],

    'errors' => [
        'file_max' => 'Ta datoteka je prevelika. Spusti sem izvoz izpiska, ki ne presega omejitve velikosti za izbrano obliko.',
        'file_extensions' => 'Ta datoteka ni videti kot podprt izvoz izpiska. Spusti sem bančni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s kartičnim izpiskom, e-poštno sporočilo (.eml) ali arhiv nabiralnika (.mbox).',
        'issuer_format' => 'Vrednost :attribute ni veljavna za vir :source.',
        'process_failed' => 'Te datoteke ni bilo mogoče obdelati (:class). Celotna napaka je v /dev/logs.',
    ],
];
