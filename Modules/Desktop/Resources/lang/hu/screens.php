<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Üdvözlünk',
        'heading' => 'Üdvözöl a Beatrax',
        'subtitle' => 'A csak helyben futó pénzügyi irányítópultod készen áll. Hozd létre az első számládat a kezdéshez.',
        'get_started' => 'Kezdés',
    ],

    'setup' => [
        'page_title' => 'Beállítás…',
        'pending_heading' => 'Beállítás…',
        'pending_body' => 'A Beatrax előkészíti az adataidat. Ez csak egy pillanat.',
        'failed_body' => 'A beállítás nem tudott befejeződni. Indítsd újra a Beatraxot; ha továbbra is hibázik, a naplóban megtalálod az okát.',
        'ready_heading' => 'Kész',
        'ready_body' => 'A beállítás kész. Folytatás…',
    ],

    'staging' => [
        'page_title' => 'Fájl megérkezett',
        'heading_prefix' => 'Fájl megérkezett: ',
        'button_label' => 'Import indítása',
        'csv_subtitle' => 'Banki vagy PayPal-export — indítsd el az importot az előnézethez és a megerősítéshez.',
        'eml_subtitle' => 'E-mailes bizonylat — indítsd el az importot, hogy a tranzakciójához csatoljuk.',
        'empty_heading' => 'Nem sikerült megnyitni ezt a fájlt',
        'empty_body' => 'A Beatrax nem tudta beolvasni a megnyitott fájlt. Próbáld inkább az Importok oldalról importálni.',
        'open_imports' => 'Importok megnyitása',
    ],

    'close' => [
        'title' => 'Fusson tovább a Beatrax?',
        'body' => 'Az ablak bezárásakor vagy teljesen kilépsz a Beatraxból, vagy csendben tovább fut a menüsávban, hogy az ütemezett e-mail-vizsgálatok folytatódjanak.',
        'button_quit' => 'Kilépés a Beatraxból',
        'button_keep_in_tray' => 'Fusson tovább a tálcán',
        'checkbox_remember' => 'Jegyezd meg a választásom',
    ],
];
