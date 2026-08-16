<?php

declare(strict_types=1);

return [
    'eyebrow' => 'PayPal-kontoen din',
    'h1' => 'Koble til PayPal-kontoen din',

    'lede_html' => 'Slipp PayPal-eksporten din med transaksjonsdetaljer — den heter <em lang="nl">Rapport Transactiegegevens</em> i en nederlandsk PayPal-konto. Saldorapporten (<span lang="nl">Saldorapport</span>) fungerer ikke — vi trenger data per hendelse.',

    'format_group_aria' => 'PayPal eksporterer bare som CSV',
    'got_it_as' => 'Du fikk det som:',
    'badge_only_format' => 'eneste format',

    'mini' => [
        'login_label' => 'Logg inn',
        'custom_label' => 'Tilpassede kontoutskrifter',
        'range_label' => 'Velg en periode',
        'range_sub' => 'Siste 12 måneder',
        'download_label' => 'Last ned som CSV',
    ],

    'drop_lead' => 'Slipp CSV-filen med transaksjonsdetaljer her',
    'browse_file' => 'eller velg en fil',

    'file_ready' => '· ✓ klar',

    'skip' => 'Hopp over dette trinnet',
    'continue' => 'Fortsett →',

    'errors' => [
        'required' => 'Slipp PayPal-CSV-filen Rapport Transactiegegevens i feltet først.',
        'max' => 'Filen er for stor. Eksporter av Rapport Transactiegegevens fra PayPal ligger vanligvis godt under 10 MB.',
        'extensions' => 'Filen ser ikke ut som en PayPal-CSV. Last ned Rapport Transactiegegevens (ikke saldorapporten Saldorapport) som CSV fra PayPal.',
        'unreadable' => 'Kunne ikke lese filen. Hele feilen står i /dev/logs.',
    ],
];
