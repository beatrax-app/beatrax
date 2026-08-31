<?php

declare(strict_types=1);

return [
    'eyebrow' => 'A PayPal-fiókod',
    'h1' => 'Kösd össze a PayPal-fiókodat',

    'lede_html' => 'Húzd ide a PayPal forgalmi exportját — tranzakciónként egy sor, nem az egyenleg összesítője. A PayPal a fiókod nyelvén nevezi el a jelentéseit, és egyelőre a holland párost olvassuk: <em lang="nl">Rapport Transactiegegevens</em>, nem a <span lang="nl">Saldorapport</span> fájlt. Ha a tiéd más nyelven jön ki, letöltés előtt állítsd a PayPalt hollandra.',

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

    'drop_lead' => 'Húzd ide a forgalmi exportodat',
    'browse_file' => 'vagy tallózz egy fájlt',

    'file_ready' => '· ✓ kész',

    'skip' => 'Lépés kihagyása',
    'continue' => 'Folytatás →',

    'errors' => [
        'required' => 'Előbb húzd a mezőbe a PayPal forgalmi exportját.',
        'max' => 'Ez a fájl túl nagy. A PayPal forgalmi exportja általában jóval 10 MB alatt van.',
        'extensions' => 'Ez a fájl nem tűnik PayPal CSV-nek. Töltsd le a forgalmi exportot — tranzakciónként egy sor, nem az egyenleg összesítője — CSV-ként.',
        'unreadable' => 'Nem sikerült beolvasni ezt a fájlt. A teljes hiba a /dev/logs alatt található.',
    ],
];
