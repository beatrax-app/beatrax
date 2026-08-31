<?php

declare(strict_types=1);

return [
    'heading' => 'Logs',
    'subtitle' => 'Live tail van het Laravel-logbestand van vandaag met dubbele redactie bij schrijven en bij streamen.',
    'truncate' => 'Leegmaken',
    'truncate_confirm' => 'Het logbestand van vandaag leegmaken? Dit kan niet ongedaan worden gemaakt.',
    'truncate_title' => 'Maak het logbestand van vandaag leeg (behoudt de inode zodat de tailer netjes doorloopt)',
    'filters_aria' => 'Logfilters',
    'severity_aria' => 'Ernstfilter',
    'channel_placeholder' => 'Kanaalfilter…',
    'channel_aria' => 'Kanaalfilter',
    'contains_placeholder' => 'Zichtbare doorzoeken…',
    'contains_aria' => 'Bevat-filter',
    'pause' => 'Pauzeren',
    'resume' => 'Hervatten',
    'waiting' => 'Wachten op logregels…',
    'copy' => 'Kopiëren',
    'copy_title' => 'Volledige regel kopiëren',
    'copy_title_copied' => 'Gekopieerd',
    'copy_aria' => 'Logregel kopiëren',
    'copy_aria_copied' => 'Gekopieerd naar klembord',
    'dismiss' => 'Verbergen',
    'dismiss_title' => 'Uit beeld verbergen (wijzigt het logbestand niet)',
    'dismiss_aria' => 'Logregel uit beeld verbergen',
    'totals' => [
        'showing' => 'Toont :shown van :count ontvangen regel (bufferlimiet :cap)|Toont :shown van :count ontvangen regels (bufferlimiet :cap)',
        'lines_today' => ':count regel vandaag|:count regels vandaag',
        'lines_today_capped' => 'meer dan :count regel vandaag|meer dan :count regels vandaag',
        'today' => 'vandaag',
        'all_files' => ':size over :count dagelijks bestand|:size over :count dagelijkse bestanden',
    ],

    'status' => [
        'poll_interrupted' => 'Log-poll onderbroken. Opnieuw proberen…',
        'paused' => 'Gepauzeerd.',
        'copy_failed_prefix' => 'Kopiëren mislukt: ',
        'clipboard_unavailable' => 'klembord niet beschikbaar',
    ],

    'toast' => [
        'truncated' => 'Log leeggemaakt — :size vrijgemaakt.',
        'nothing' => 'Niets om leeg te maken.',
    ],
];
