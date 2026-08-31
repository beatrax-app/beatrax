<?php

declare(strict_types=1);

return [
    'page_title' => 'Podaci i uređaji',
    'heading' => 'Podaci i uređaji',
    'sync_status' => 'Stanje sinhronizacije',
    'syncing_progress' => 'Sinhronizacija… :count zapis|Sinhronizacija… :count zapisa|Sinhronizacija… :count zapisa',
    'initial_sync_aria' => 'Napredak početne sinhronizacije',
    'no_peers' => 'Upari drugi uređaj da pokreneš sinhronizaciju.',
    'sync_now' => 'Sinhronizuj sada',
    'result' => [
        'synced' => 'Sinhronizovano sa drugim uređajem.',
        'unreachable' => 'Drugi uređaj nije dostupan — proveri da li su oba na istoj mreži.',
        'locked' => 'Otključaj aplikaciju za sinhronizaciju.',
        'not_enabled' => 'Sinhronizacija na ovom uređaju još nije podešena.',
        'unreadable' => 'Ključ ovog uređaja više ne može da se otvori. Ponovo upari uređaje da nastaviš sinhronizaciju.',
        'paused_on_cellular' => 'Pauzirano — sinhronizacija je ograničena na Wi-Fi, a koristiš mobilne podatke.',
    ],
    'background_note' => 'Sinhronizacija se dešava kad dodirneš Sinhronizuj sada. U pozadini ne može da radi — zaključavanje aplikacije čuva jedini ključ.',
    'network' => 'Mreža',
    'pause_cellular' => 'Zaustavi sinhronizaciju na mobilnoj mreži',
    'pause_cellular_help' => 'Podrazumevano isključeno — sinhronizacija radi svuda. Uključi da se sinhronizuje samo preko Wi-Fi mreže.',
];
