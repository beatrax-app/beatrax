<?php

declare(strict_types=1);

return [
    'page_title' => 'Dati e dispositivi',
    'heading' => 'Dati e dispositivi',
    'sync_status' => 'Stato della sincronizzazione',
    'syncing_progress' => 'Sincronizzazione… :count record|Sincronizzazione… :count record',
    'initial_sync_aria' => 'Avanzamento della sincronizzazione iniziale',
    'no_peers' => 'Abbina un altro dispositivo per iniziare a sincronizzare.',
    'sync_now' => 'Sincronizza ora',
    'result' => [
        'synced' => 'Sincronizzato con il tuo altro dispositivo.',
        'unreachable' => 'Impossibile raggiungere il tuo altro dispositivo — controlla che siano sulla stessa rete.',
        'locked' => 'Sblocca l\'app per sincronizzare.',
        'not_enabled' => 'La sincronizzazione non è ancora configurata su questo dispositivo.',
        'unreadable' => 'La chiave di questo dispositivo non si apre più. Abbina di nuovo per riprendere la sincronizzazione.',
        'paused_on_cellular' => 'In pausa — la sincronizzazione è limitata al Wi-Fi e stai usando i dati mobili.',
    ],
    'background_note' => 'La sincronizzazione avviene quando tocchi Sincronizza ora. Non può girare in background — il blocco app custodisce l\'unica chiave.',
    'network' => 'Rete',
    'pause_cellular' => 'Sospendi la sincronizzazione su rete mobile',
    'pause_cellular_help' => 'Disattivato per impostazione predefinita — la sincronizzazione funziona ovunque. Attivalo per sincronizzare solo tramite Wi-Fi.',
];
