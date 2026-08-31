<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Din PayPal-konto',
    'h1' => 'Forbind din PayPal-konto',

    'lede_html' => 'Slip din PayPal-aktivitetseksport — én række per transaktion, ikke saldooversigten. PayPal navngiver sine rapporter på dit kontos sprog, og indtil videre læser vi det hollandske par: <em lang="nl">Rapport Transactiegegevens</em>, ikke <span lang="nl">Saldorapport</span>. Kommer din på et andet sprog, så skift PayPal til hollandsk, før du henter den.',

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

    'drop_lead' => 'Slip din aktivitetseksport her',
    'browse_file' => 'eller vælg en fil',

    'file_ready' => '· ✓ klar',

    'skip' => 'Spring dette trin over',
    'continue' => 'Fortsæt →',

    'errors' => [
        'required' => 'Slip først din PayPal-aktivitetseksport i feltet.',
        'max' => 'Filen er for stor. En PayPal-aktivitetseksport ligger normalt et godt stykke under 10 MB.',
        'extensions' => 'Filen ligner ikke en PayPal-CSV. Hent aktivitetseksporten — én række per transaktion, ikke saldooversigten — som CSV.',
        'unreadable' => 'Kunne ikke læse filen. Hele fejlen står i /dev/logs.',
    ],
];
