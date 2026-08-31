<?php

declare(strict_types=1);

return [
    'page_title' => 'Date și dispozitive',
    'heading' => 'Date și dispozitive',
    'sync_status' => 'Starea sincronizării',
    'syncing_progress' => 'Se sincronizează… :count înregistrare|Se sincronizează… :count înregistrări|Se sincronizează… :count de înregistrări',
    'initial_sync_aria' => 'Progresul sincronizării inițiale',
    'no_peers' => 'Împerechează un alt dispozitiv pentru a începe sincronizarea.',
    'sync_now' => 'Sincronizează acum',
    'result' => [
        'synced' => 'Sincronizat cu celălalt dispozitiv.',
        'unreachable' => 'Celălalt dispozitiv nu poate fi contactat — verifică dacă amândouă sunt în aceeași rețea.',
        'locked' => 'Deblochează aplicația pentru a sincroniza.',
        'not_enabled' => 'Sincronizarea nu este încă configurată pe acest dispozitiv.',
        'unreadable' => 'Cheia acestui dispozitiv nu se mai deschide. Asociază din nou pentru a relua sincronizarea.',
        'paused_on_cellular' => 'În pauză — sincronizarea este limitată la Wi-Fi, iar tu ești pe date mobile.',
    ],
    'background_note' => 'Sincronizarea are loc când atingi Sincronizează acum. Nu poate rula în fundal — blocarea aplicației păstrează singura cheie.',
    'network' => 'Rețea',
    'pause_cellular' => 'Suspendă sincronizarea pe date mobile',
    'pause_cellular_help' => 'Dezactivat implicit — sincronizarea funcționează peste tot. Activează-l pentru a sincroniza doar prin Wi-Fi.',
];
