<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Din PayPal-konto',
    'h1' => 'Forbind din PayPal-konto',

    'lede_html' => 'Slip din PayPal-eksport med transaktionsdetaljer — den hedder <em lang="nl">Rapport Transactiegegevens</em> i en hollandsk PayPal-konto. Saldorapporten (<span lang="nl">Saldorapport</span>) virker ikke — vi skal bruge data pr. hændelse.',

    'format_group_aria' => 'PayPal eksporterer kun som CSV',
    'got_it_as' => 'Du fik det som:',
    'badge_only_format' => 'eneste format',

    'mini' => [
        'login_label' => 'Log ind',
        'custom_label' => 'Tilpassede kontoudtog',
        'range_label' => 'Vælg en periode',
        'range_sub' => 'Seneste 12 måneder',
        'download_label' => 'Hent som CSV',
    ],

    'drop_lead' => 'Slip din CSV med transaktionsdetaljer her',
    'browse_file' => 'eller vælg en fil',

    'file_ready' => '· ✓ klar',

    'skip' => 'Spring dette trin over',
    'continue' => 'Fortsæt →',

    'errors' => [
        'required' => 'Slip først din PayPal-CSV Rapport Transactiegegevens i feltet.',
        'max' => 'Filen er for stor. Eksporter af Rapport Transactiegegevens fra PayPal ligger normalt et godt stykke under 10 MB.',
        'extensions' => 'Filen ligner ikke en PayPal-CSV. Hent Rapport Transactiegegevens (ikke saldorapporten Saldorapport) som CSV fra PayPal.',
        'unreadable' => 'Kunne ikke læse filen. Hele fejlen står i /dev/logs.',
    ],
];
