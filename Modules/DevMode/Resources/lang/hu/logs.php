<?php

declare(strict_types=1);

return [
    'heading' => 'Naplók',
    'subtitle' => 'Az aznapi Laravel-naplófájl élő követése, kétszeres biztonsággal: íráskori és streamelés közbeni maszkolással.',
    'truncate' => 'Ürítés',
    'truncate_confirm' => 'Kiüríted a mai naplófájlt? Ez nem vonható vissza.',
    'truncate_title' => 'A mai naplófájl kiürítése (megőrzi az inode-ot, így a követés zökkenőmentesen folytatódik)',
    'filters_aria' => 'Naplószűrők',
    'severity_aria' => 'Súlyossági szűrő',
    'channel_placeholder' => 'Csatornaszűrő…',
    'channel_aria' => 'Csatornaszűrő',
    'contains_placeholder' => 'Keresés a láthatókban…',
    'contains_aria' => 'Tartalmaz szűrő',
    'pause' => 'Szünet',
    'resume' => 'Folytatás',
    'waiting' => 'Várakozás naplósorokra…',
    'copy' => 'Másolás',
    'copy_title' => 'Teljes bejegyzés másolása',
    'copy_title_copied' => 'Másolva',
    'copy_aria' => 'Naplóbejegyzés másolása',
    'copy_aria_copied' => 'Vágólapra másolva',
    'dismiss' => 'Elvetés',
    'dismiss_title' => 'Elrejtés a nézetből (nem módosítja a naplófájlt)',
    'dismiss_aria' => 'Naplóbejegyzés elvetése a nézetből',
    'totals' => [
        'showing' => 'Megjelenítve',
        'of' => '/',
        'received' => 'fogadottból (puffer korlát 10k)',
        'lines_today' => 'sor ma',
        'today' => 'ma',
        'across' => 'összesen',
        'daily_files' => 'napi fájlban',
    ],

    'status' => [
        'poll_interrupted' => 'A napló lekérdezése megszakadt. Újrapróbálás…',
        'paused' => 'Szüneteltetve.',
        'copy_failed_prefix' => 'A másolás nem sikerült: ',
        'clipboard_unavailable' => 'a vágólap nem érhető el',
    ],

    'toast' => [
        'truncated' => 'Napló kiürítve — :size felszabadult.',
        'nothing' => 'Nincs mit üríteni.',
    ],
];
