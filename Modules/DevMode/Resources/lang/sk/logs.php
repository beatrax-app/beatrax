<?php

declare(strict_types=1);

return [
    'heading' => 'Logy',
    'subtitle' => 'Živý výpis dnešného Laravel log súboru s dvojitým skrývaním citlivých údajov — pri zápise aj pri streamovaní.',
    'truncate' => 'Vyprázdniť',
    'truncate_confirm' => 'Vyprázdniť dnešný log súbor? Túto akciu nemožno vrátiť späť.',
    'truncate_title' => 'Vyprázdni dnešný log súbor (zachová inode, aby výpis pokračoval bez prerušenia)',
    'filters_aria' => 'Filtre logov',
    'severity_aria' => 'Filter závažnosti',
    'channel_placeholder' => 'Filter kanála…',
    'channel_aria' => 'Filter kanála',
    'contains_placeholder' => 'Hľadať vo zobrazených…',
    'contains_aria' => 'Filter obsahu',
    'pause' => 'Pozastaviť',
    'resume' => 'Pokračovať',
    'waiting' => 'Čaká sa na riadky logu…',
    'copy' => 'Kopírovať',
    'copy_title' => 'Skopírovať celý záznam',
    'copy_title_copied' => 'Skopírované',
    'copy_aria' => 'Skopírovať záznam logu',
    'copy_aria_copied' => 'Skopírované do schránky',
    'dismiss' => 'Zamietnuť',
    'dismiss_title' => 'Zamietnuť záznam a skryť ho zo zobrazenia (log súbor sa nemení)',
    'dismiss_aria' => 'Zamietnuť záznam logu a skryť ho zo zobrazenia',
    'totals' => [
        'showing' => 'Zobrazuje sa :shown z :count prijatého riadka (limit vyrovnávacej pamäte :cap)|Zobrazuje sa :shown z :count prijatých riadkov (limit vyrovnávacej pamäte :cap)|Zobrazuje sa :shown z :count prijatých riadkov (limit vyrovnávacej pamäte :cap)',
        'lines_today' => ':count riadok dnes|:count riadky dnes|:count riadkov dnes',
        'lines_today_capped' => 'viac než :count riadok dnes|viac než :count riadky dnes|viac než :count riadkov dnes',
        'today' => 'dnes',
        'all_files' => ':size v :count dennom súbore|:size v :count denných súboroch|:size v :count denných súboroch',
    ],

    'status' => [
        'poll_interrupted' => 'Načítavanie logu bolo prerušené. Skúša sa znova…',
        'paused' => 'Pozastavené.',
        'copy_failed_prefix' => 'Kopírovanie zlyhalo: ',
        'clipboard_unavailable' => 'schránka nie je dostupná',
    ],

    'toast' => [
        'truncated' => 'Log vyprázdnený — uvoľnené :size.',
        'nothing' => 'Niet čo vyprázdniť.',
    ],
];
