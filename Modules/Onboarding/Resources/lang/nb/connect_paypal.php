<?php

declare(strict_types=1);

return [
    'eyebrow' => 'PayPal-kontoen din',
    'h1' => 'Koble til PayPal-kontoen din',

    'lede_html' => 'Slipp PayPal-aktivitetseksporten din — én rad per transaksjon, ikke saldooversikten. PayPal navngir rapportene sine på språket til kontoen din, og foreløpig leser vi det nederlandske paret: <em lang="nl">Rapport Transactiegegevens</em>, ikke <span lang="nl">Saldorapport</span>. Kommer din på et annet språk, bytt PayPal til nederlandsk før du laster ned.',

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

    'drop_lead' => 'Slipp aktivitetseksporten din her',
    'browse_file' => 'eller velg en fil',

    'file_ready' => '· ✓ klar',

    'skip' => 'Hopp over dette trinnet',
    'continue' => 'Fortsett →',

    'errors' => [
        'required' => 'Slipp PayPal-aktivitetseksporten din i feltet først.',
        'max' => 'Filen er for stor. En PayPal-aktivitetseksport ligger vanligvis godt under 10 MB.',
        'extensions' => 'Filen ser ikke ut som en PayPal-CSV. Last ned aktivitetseksporten — én rad per transaksjon, ikke saldooversikten — som CSV.',
        'unreadable' => 'Kunne ikke lese filen. Hele feilen står i /dev/logs.',
    ],
];
