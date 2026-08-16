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
        'showing' => 'Zobrazené',
        'of' => 'z',
        'received' => 'prijatých (limit vyrovnávacej pamäte 10k)',
        'lines_today' => 'riadkov dnes',
        'today' => 'dnes',
        'across' => 'v',
        'daily_files' => 'denných súboroch',
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
