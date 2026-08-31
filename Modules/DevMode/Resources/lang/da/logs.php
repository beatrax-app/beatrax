<?php

declare(strict_types=1);

return [
    'heading' => 'Logs',
    'subtitle' => 'Live-tail af dagens Laravel-logfil med dobbelt maskering både ved skrivning og ved streaming.',
    'truncate' => 'Tøm',
    'truncate_confirm' => 'Tøm dagens logfil? Det kan ikke fortrydes.',
    'truncate_title' => 'Tøm dagens logfil (bevarer inoden, så taileren fortsætter uden afbrydelser)',
    'filters_aria' => 'Logfiltre',
    'severity_aria' => 'Alvorlighedsfilter',
    'channel_placeholder' => 'Kanalfilter…',
    'channel_aria' => 'Kanalfilter',
    'contains_placeholder' => 'Søg i synlige…',
    'contains_aria' => 'Indeholder-filter',
    'pause' => 'Pause',
    'resume' => 'Genoptag',
    'waiting' => 'Venter på loglinjer…',
    'copy' => 'Kopiér',
    'copy_title' => 'Kopiér hele posten',
    'copy_title_copied' => 'Kopieret',
    'copy_aria' => 'Kopiér logpost',
    'copy_aria_copied' => 'Kopieret til udklipsholderen',
    'dismiss' => 'Skjul',
    'dismiss_title' => 'Skjul fra visningen (ændrer ikke logfilen)',
    'dismiss_aria' => 'Skjul logposten fra visningen',
    'totals' => [
        'showing' => 'Viser :shown af :count modtaget linje (buffergrænse :cap)|Viser :shown af :count modtagne linjer (buffergrænse :cap)',
        'lines_today' => ':count linje i dag|:count linjer i dag',
        'lines_today_capped' => 'over :count linje i dag|over :count linjer i dag',
        'today' => 'i dag',
        'all_files' => ':size fordelt på :count daglig fil|:size fordelt på :count daglige filer',
    ],

    'status' => [
        'poll_interrupted' => 'Log-pollingen blev afbrudt. Prøver igen…',
        'paused' => 'Sat på pause.',
        'copy_failed_prefix' => 'Kopiering mislykkedes: ',
        'clipboard_unavailable' => 'udklipsholder ikke tilgængelig',
    ],

    'toast' => [
        'truncated' => 'Loggen blev tømt — frigjorde :size.',
        'nothing' => 'Intet at tømme.',
    ],
];
