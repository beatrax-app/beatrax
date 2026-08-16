<?php

declare(strict_types=1);

return [
    'eyebrow' => 'A PayPal-fiókod',
    'h1' => 'Kösd össze a PayPal-fiókodat',

    'lede_html' => 'Húzd ide a PayPal tranzakciórészletek-exportját — holland PayPal-fiókban <em lang="nl">Rapport Transactiegegevens</em> néven szerepel. Az egyenlegjelentés (<span lang="nl">Saldorapport</span>) nem működik — eseményenkénti adatokra van szükségünk.',

    'format_group_aria' => 'A PayPal csak CSV-be exportál',
    'got_it_as' => 'Így kaptad meg:',
    'badge_only_format' => 'egyetlen formátum',

    'mini' => [
        'login_label' => 'Bejelentkezés',
        'custom_label' => 'Egyedi kivonatok',
        'range_label' => 'Válassz időszakot',
        'range_sub' => 'Elmúlt 12 hónap',
        'download_label' => 'Letöltés CSV-ként',
    ],

    'drop_lead' => 'Húzd ide a tranzakciórészletek CSV-t',
    'browse_file' => 'vagy tallózz egy fájlt',

    'file_ready' => '· ✓ kész',

    'skip' => 'Lépés kihagyása',
    'continue' => 'Folytatás →',

    'errors' => [
        'required' => 'Előbb húzd a PayPal Rapport Transactiegegevens CSV-t a mezőbe.',
        'max' => 'Ez a fájl túl nagy. A PayPal Rapport Transactiegegevens exportok általában jóval 10 MB alatt vannak.',
        'extensions' => 'Ez a fájl nem tűnik PayPal CSV-nek. Töltsd le a PayPalról a Rapport Transactiegegevens fájlt CSV-ként (ne a Saldorapport egyenlegjelentést).',
        'unreadable' => 'Nem sikerült beolvasni ezt a fájlt. A teljes hiba a /dev/logs alatt található.',
    ],
];
