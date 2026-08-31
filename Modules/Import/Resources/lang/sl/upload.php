<?php

declare(strict_types=1);

return [
    'page_title' => 'Naloži izpisek',
    'heading' => 'Naloži izpisek',
    'migrate_prompt' => 'Prehajaš iz druge aplikacije za proračun?',
    'migrate_link' => 'Uvozi iz YNAB ali Actual',
    'subtitle' => 'Spusti sem izpisek v obliki CSV, CAMT.053, MT940 ali PDF ali datoteko z e-poštnim potrdilom.',
    'mime_hint' => 'Podprte datoteke: bančni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF izpiska kartice, e-poštno sporočilo (.eml) ali arhiv nabiralnika (.mbox).',

    'type_label' => 'Vrsta uvoza',

    'types' => [
        'csv' => 'Datoteka CSV',
        'camt053' => 'Izpisek CAMT.053 (XML)',
        'mt940' => 'Izpisek MT940',
        'pdf' => 'Kartični izpisek (PDF)',
        'email' => 'Datoteka z e-poštnim potrdilom',
    ],

    'format_label' => 'Oblika',

    'format_from_file' => 'Oblika je bila nastavljena na :format, da se ujema z izbrano datoteko. Spremeni jo, če to ne drži.',
    'file_label' => 'Datoteka',
    'submit' => 'Naloži izpisek',

    'formats' => [
        'activity_download' => 'Prenos aktivnosti (CSV)',
        'email_message' => 'E-poštno sporočilo (.eml)',
        'mailbox_archive' => 'Arhiv nabiralnika (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Ta datoteka je prevelika. Spusti sem izvoz izpiska, ki ne presega omejitve velikosti za izbrano obliko.',
        'file_extensions' => 'Ta datoteka ni videti kot podprt izvoz izpiska. Spusti sem bančni CSV, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF s kartičnim izpiskom, e-poštno sporočilo (.eml) ali arhiv nabiralnika (.mbox).',
        'type_format' => 'Vrednost :attribute ni veljavna za vrsto uvoza :type.',
        'process_failed' => 'Te datoteke ni bilo mogoče obdelati (:class). Celotna napaka je v /dev/logs.',
    ],
];
