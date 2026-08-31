<?php

declare(strict_types=1);

return [
    'page_title' => 'Podaci i uređaji',
    'heading' => 'Podaci i uređaji',
    'sync_status' => 'Stanje sinkronizacije',
    'syncing_progress' => 'Sinkronizacija… :count zapis|Sinkronizacija… :count zapisa|Sinkronizacija… :count zapisa',
    'initial_sync_aria' => 'Napredak početne sinkronizacije',
    'no_peers' => 'Upari drugi uređaj da pokreneš sinkronizaciju.',
    'sync_now' => 'Sinkroniziraj sada',
    'result' => [
        'synced' => 'Sinkronizirano s drugim uređajem.',
        'unreachable' => 'Drugi uređaj nije dostupan — provjeri jesu li oba na istoj mreži.',
        'locked' => 'Otključaj aplikaciju za sinkronizaciju.',
        'not_enabled' => 'Sinkronizacija na ovom uređaju još nije postavljena.',
        'unreadable' => 'Ključ ovog uređaja više se ne otvara. Ponovno upari uređaje da nastaviš sinkronizaciju.',
        'paused_on_cellular' => 'Pauzirano — sinkronizacija je ograničena na Wi-Fi, a koristiš mobilne podatke.',
    ],
    'background_note' => 'Sinkronizacija se događa kad dodirneš Sinkroniziraj sada. U pozadini ne može raditi — zaključavanje aplikacije čuva jedini ključ.',
    'network' => 'Mreža',
    'pause_cellular' => 'Zaustavi sinkronizaciju na mobilnoj mreži',
    'pause_cellular_help' => 'Prema zadanome isključeno — sinkronizacija radi svugdje. Uključi da se sinkronizira samo preko Wi-Fija.',
];
