<?php

declare(strict_types=1);

return [
    'page_title' => 'Dados e dispositivos',
    'heading' => 'Dados e dispositivos',
    'sync_status' => 'Estado da sincronização',
    'syncing_progress' => 'A sincronizar… :count registo|A sincronizar… :count registos',
    'initial_sync_aria' => 'Progresso da sincronização inicial',
    'no_peers' => 'Emparelha outro dispositivo para começar a sincronizar.',
    'sync_now' => 'Sincronizar agora',
    'result' => [
        'synced' => 'Sincronizado com o teu outro dispositivo.',
        'unreachable' => 'Não foi possível contactar o teu outro dispositivo — verifica se ambos estão na mesma rede.',
        'locked' => 'Desbloqueia a app para sincronizar.',
        'not_enabled' => 'A sincronização ainda não está configurada neste dispositivo.',
        'unreadable' => 'A chave deste dispositivo já não abre. Emparelha de novo para retomar a sincronização.',
        'paused_on_cellular' => 'Em pausa — a sincronização está limitada a Wi-Fi e estás com dados móveis.',
    ],
    'background_note' => 'A sincronização acontece quando tocas em Sincronizar agora. Não pode correr em segundo plano — o bloqueio da app guarda a única chave.',
    'network' => 'Rede',
    'pause_cellular' => 'Pausar a sincronização em dados móveis',
    'pause_cellular_help' => 'Desligado por predefinição — a sincronização funciona em qualquer lado. Liga para sincronizar apenas por Wi-Fi.',
];
