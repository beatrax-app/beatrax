<?php

declare(strict_types=1);

return [
    'page_title' => 'Données et appareils',
    'heading' => 'Données et appareils',
    'sync_status' => 'État de la synchronisation',
    'syncing_progress' => 'Synchronisation… :count enregistrement|Synchronisation… :count enregistrements',
    'initial_sync_aria' => 'Progression de la synchronisation initiale',
    'no_peers' => 'Appaire un autre appareil pour lancer la synchronisation.',
    'sync_now' => 'Synchroniser maintenant',
    'result' => [
        'synced' => 'Synchronisé avec ton autre appareil.',
        'unreachable' => 'Impossible de joindre ton autre appareil — vérifie que les deux sont sur le même réseau.',
        'locked' => 'Déverrouille l\'app pour synchroniser.',
        'not_enabled' => 'La synchronisation n\'est pas encore configurée sur cet appareil.',
        'unreadable' => 'La clé de cet appareil ne s\'ouvre plus. Appaire à nouveau pour reprendre la synchronisation.',
        'paused_on_cellular' => 'En pause — la synchro est limitée au Wi-Fi et tu es en données mobiles.',
    ],
    'background_note' => 'Beatrax reste à l\'écoute tant qu\'il est ouvert : un appareil appairé peut donc se synchroniser avec celui-ci à tout moment. Synchroniser maintenant lance un échange de données depuis ce côté.',
    'background_note_phone' => 'La synchronisation a lieu quand tu appuies sur Synchroniser maintenant. Elle ne peut pas tourner en arrière-plan — le verrou de l\'app détient la seule clé.',
    'network' => 'Réseau',
    'pause_cellular' => 'Suspendre la synchro en données mobiles',
    'pause_cellular_help' => 'Désactivé par défaut — la synchronisation fonctionne partout. Active-le pour ne synchroniser qu\'en Wi-Fi.',
];
