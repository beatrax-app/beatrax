<?php

declare(strict_types=1);

return [
    'heading' => 'Logy',
    'subtitle' => 'Živé sledování dnešního souboru logů Laravelu s dvojitým jištěním: redakcí při zápisu i při streamování.',
    'truncate' => 'Vyprázdnit',
    'truncate_confirm' => 'Vyprázdnit dnešní soubor logů? Tohle nejde vzít zpět.',
    'truncate_title' => 'Vyprázdní dnešní soubor logů (zachová i-uzel, takže sledování plynule pokračuje)',
    'filters_aria' => 'Filtry logů',
    'severity_aria' => 'Filtr závažnosti',
    'channel_placeholder' => 'Filtr kanálu…',
    'channel_aria' => 'Filtr kanálu',
    'contains_placeholder' => 'Hledat ve zobrazených…',
    'contains_aria' => 'Filtr obsahu',
    'pause' => 'Pozastavit',
    'resume' => 'Pokračovat',
    'waiting' => 'Čeká se na řádky logu…',
    'copy' => 'Kopírovat',
    'copy_title' => 'Kopírovat celý záznam',
    'copy_title_copied' => 'Zkopírováno',
    'copy_aria' => 'Kopírovat záznam logu',
    'copy_aria_copied' => 'Zkopírováno do schránky',
    'dismiss' => 'Zamítnout',
    'dismiss_title' => 'Zamítnout ze zobrazení (soubor logu se nemění)',
    'dismiss_aria' => 'Zamítnout záznam logu ze zobrazení',
    'totals' => [
        'showing' => 'Zobrazeno :shown z :count přijatého řádku (limit bufferu :cap)|Zobrazeno :shown z :count přijatých řádků (limit bufferu :cap)|Zobrazeno :shown z :count přijatých řádků (limit bufferu :cap)',
        'lines_today' => ':count řádek dnes|:count řádky dnes|:count řádků dnes',
        'lines_today_capped' => 'přes :count řádek dnes|přes :count řádky dnes|přes :count řádků dnes',
        'today' => 'dnes',
        'all_files' => ':size v :count denním souboru|:size ve :count denních souborech|:size v :count denních souborech',
    ],

    'status' => [
        'poll_interrupted' => 'Dotazování na logy přerušeno. Zkouší se znovu…',
        'paused' => 'Pozastaveno.',
        'copy_failed_prefix' => 'Kopírování selhalo: ',
        'clipboard_unavailable' => 'schránka není dostupná',
    ],

    'toast' => [
        'truncated' => 'Log vyprázdněn — uvolněno :size.',
        'nothing' => 'Není co vyprázdnit.',
    ],
];
