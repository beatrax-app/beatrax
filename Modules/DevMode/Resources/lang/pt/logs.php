<?php

declare(strict_types=1);

return [
    'heading' => 'Registos',
    'subtitle' => 'Leitura em direto do ficheiro de registo do Laravel do dia atual, com dupla redação na escrita e na transmissão.',
    'truncate' => 'Truncar',
    'truncate_confirm' => 'Truncar o ficheiro de registo de hoje? Isto não pode ser anulado.',
    'truncate_title' => 'Esvaziar o ficheiro de registo de hoje (preserva o inode para a leitura retomar sem falhas)',
    'filters_aria' => 'Filtros de registo',
    'severity_aria' => 'Filtro de gravidade',
    'channel_placeholder' => 'Filtrar por canal…',
    'channel_aria' => 'Filtro de canal',
    'contains_placeholder' => 'Pesquisar no visível…',
    'contains_aria' => 'Filtro por conteúdo',
    'pause' => 'Pausar',
    'resume' => 'Retomar',
    'waiting' => 'À espera de linhas de registo…',
    'copy' => 'Copiar',
    'copy_title' => 'Copiar a entrada completa',
    'copy_title_copied' => 'Copiado',
    'copy_aria' => 'Copiar a entrada de registo',
    'copy_aria_copied' => 'Copiado para a área de transferência',
    'dismiss' => 'Dispensar',
    'dismiss_title' => 'Dispensar da vista (não altera o ficheiro de registo)',
    'dismiss_aria' => 'Dispensar a entrada de registo da vista',
    'totals' => [
        'showing' => 'A mostrar',
        'of' => 'de',
        'received' => 'recebidas (limite do buffer 10k)',
        'lines_today' => 'linhas hoje',
        'today' => 'hoje',
        'across' => 'em',
        'daily_files' => 'ficheiros diários',
    ],

    'status' => [
        'poll_interrupted' => 'Leitura do registo interrompida. A tentar novamente…',
        'paused' => 'Em pausa.',
        'copy_failed_prefix' => 'Falha ao copiar: ',
        'clipboard_unavailable' => 'área de transferência indisponível',
    ],

    'toast' => [
        'truncated' => 'Registo truncado — libertou :size.',
        'nothing' => 'Nada para truncar.',
    ],
];
