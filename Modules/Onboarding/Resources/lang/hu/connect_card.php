<?php

declare(strict_types=1);

return [
    'eyebrow' => 'A hitelkártyád',
    'h1' => 'Szerezd be a havi kivonatok PDF-jeit',
    'lede' => 'Húzd ide az összes havi PDF-kivonatot — egyetlen előnézetbe fésüljük őket.',

    'format_group_aria' => 'Az ICS csak PDF-be exportál',
    'issuer_note' => 'Az ICS egyelőre az egyetlen kártyakibocsátó, amelyet el tudunk olvasni, és csak a holland nyelvű kivonatát. Ha a kártyád más kibocsátótól van, hagyd ki ezt a lépést.',
    'got_it_as' => 'Így kaptad meg:',
    'badge_only_format' => 'egyetlen formátum',

    'mini' => [
        'login_label' => 'Bejelentkezés',
        'statements_label' => 'Kivonatok megnyitása',
        'months_label' => 'Válassz hónapokat',
        'months_sub' => 'Havonta egy PDF',
        'download_label' => 'Letöltés',
    ],

    'drop_lead' => 'Húzd ide az ICS PDF-eket',
    'browse_files' => 'vagy tallózz fájlokat',
    'queue_aria' => 'Sorba állított PDF-kivonatok',

    'skip' => 'Lépés kihagyása',
    'continue' => 'Folytatás →',

    'errors' => [
        'required' => 'Húzd ide a Mijn ICS-ből letöltött havi PDF-kivonatokat.',
        'min' => 'A folytatás előtt húzz ide legalább egy ICS PDF-kivonatot.',
        'each_required' => 'Húzd ide a Mijn ICS-ből letöltött havi PDF-kivonatot.',
        'each_max' => 'Az egyik fájlod túl nagy. Az ICS PDF-kivonatok általában 1 MB alatt vannak.',
        'each_extensions' => 'Az egyik fájlod nem PDF. A Mijn ICS csak PDF-et exportál — próbáld a legutóbbi havi kivonatot.',
        'file_unreadable' => 'Nem sikerült beolvasni: :filename. A teljes hiba a /dev/logs alatt található.',
        'none_readable' => 'Egyik ICS PDF-edet sem sikerült beolvasni. :detail',
        'full_error_in_logs' => 'A teljes hiba a /dev/logs alatt található.',
    ],
];
