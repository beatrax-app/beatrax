<?php

declare(strict_types=1);

return [
    'page_title' => 'Datos y dispositivos',
    'heading' => 'Datos y dispositivos',
    'sync_status' => 'Estado de sincronización',
    'syncing_progress' => 'Sincronizando… :count registro|Sincronizando… :count registros',
    'initial_sync_aria' => 'Progreso de la sincronización inicial',
    'no_peers' => 'Vincula otro dispositivo para empezar a sincronizar.',
    'sync_now' => 'Sincronizar ahora',
    'result' => [
        'synced' => 'Sincronizado con tu otro dispositivo.',
        'unreachable' => 'No se pudo contactar con tu otro dispositivo — comprueba que ambos estén en la misma red.',
        'locked' => 'Desbloquea la app para sincronizar.',
        'not_enabled' => 'La sincronización aún no está configurada en este dispositivo.',
        'unreadable' => 'La clave de este dispositivo ya no se abre. Vuelve a emparejar para retomar la sincronización.',
        'paused_on_cellular' => 'En pausa — la sincronización está limitada a Wi-Fi y estás usando datos móviles.',
    ],
    'background_note' => 'Beatrax sigue escuchando mientras está abierto, así que un dispositivo vinculado puede sincronizarse con este en cualquier momento. Sincronizar ahora inicia un intercambio de datos desde este lado.',
    'background_note_phone' => 'La sincronización ocurre cuando tocas Sincronizar ahora. No puede ejecutarse en segundo plano — el bloqueo de la app guarda la única clave.',
    'network' => 'Red',
    'pause_cellular' => 'Pausar la sincronización con datos móviles',
    'pause_cellular_help' => 'Desactivado por defecto — la sincronización funciona en todas partes. Actívalo para sincronizar solo por Wi-Fi.',
];
