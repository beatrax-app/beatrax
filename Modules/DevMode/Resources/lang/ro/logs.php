<?php

declare(strict_types=1);

return [
    'heading' => 'Jurnale',
    'subtitle' => 'Urmărire live a fișierului de jurnal Laravel din ziua curentă, cu dublă siguranță: redactare la scriere și la transmitere.',
    'truncate' => 'Golește',
    'truncate_confirm' => 'Golești fișierul de jurnal de azi? Acțiunea nu poate fi anulată.',
    'truncate_title' => 'Golește fișierul de jurnal de azi (păstrează inode-ul, așa că urmărirea se reia curat)',
    'filters_aria' => 'Filtre de jurnal',
    'severity_aria' => 'Filtru de severitate',
    'channel_placeholder' => 'Filtru de canal…',
    'channel_aria' => 'Filtru de canal',
    'contains_placeholder' => 'Caută în cele vizibile…',
    'contains_aria' => 'Filtru de conținut',
    'pause' => 'Pauză',
    'resume' => 'Reia',
    'waiting' => 'Se așteaptă rânduri de jurnal…',
    'copy' => 'Copiază',
    'copy_title' => 'Copiază intrarea completă',
    'copy_title_copied' => 'Copiat',
    'copy_aria' => 'Copiază intrarea de jurnal',
    'copy_aria_copied' => 'Copiat în clipboard',
    'dismiss' => 'Închide',
    'dismiss_title' => 'Ascunde din vizualizare (nu modifică fișierul de jurnal)',
    'dismiss_aria' => 'Ascunde intrarea de jurnal din vizualizare',
    'totals' => [
        'showing' => 'Se afișează :shown din :count rând primit (limită buffer :cap)|Se afișează :shown din :count rânduri primite (limită buffer :cap)|Se afișează :shown din :count de rânduri primite (limită buffer :cap)',
        'lines_today' => ':count rând azi|:count rânduri azi|:count de rânduri azi',
        'lines_today_capped' => 'peste :count rând azi|peste :count rânduri azi|peste :count de rânduri azi',
        'today' => 'azi',
        'all_files' => ':size în :count fișier zilnic|:size în :count fișiere zilnice|:size în :count de fișiere zilnice',
    ],

    'status' => [
        'poll_interrupted' => 'Interogarea jurnalului a fost întreruptă. Se reîncearcă…',
        'paused' => 'În pauză.',
        'copy_failed_prefix' => 'Copierea a eșuat: ',
        'clipboard_unavailable' => 'clipboard indisponibil',
    ],

    'toast' => [
        'truncated' => 'Jurnal golit — s-au eliberat :size.',
        'nothing' => 'Nu e nimic de golit.',
    ],
];
