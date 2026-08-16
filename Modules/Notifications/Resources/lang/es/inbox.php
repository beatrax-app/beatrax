<?php

declare(strict_types=1);

return [
    'heading' => 'Notificaciones',
    'page_title' => 'Notificaciones',
    'settings_link' => 'Ajustes de notificaciones →',
    'load_more' => 'Cargar más',

    'tablist_aria' => 'Ciclo de vida de las notificaciones',
    'tabs' => [
        'unread' => 'Sin leer',
        'all' => 'Todas',
        'dismissed' => 'Descartadas',
    ],

    'empty' => [
        'unread' => [
            'heading' => 'Estás al día',
            'body' => 'Aquí llegan las notificaciones nuevas: recordatorios de pago, avisos de presupuesto y tu situación semanal.',
        ],
        'all' => [
            'heading' => 'Nada por ahora',
            'body' => 'Beatrax te avisará de los próximos pagos y de cualquier cosa que convenga revisar.',
        ],
        'dismissed' => [
            'heading' => 'Nada descartado',
            'body' => 'Las notificaciones que descartas se guardan aquí durante un tiempo.',
        ],
    ],

    'toast' => [
        'dismissed' => 'Descartada — Deshacer',
        'restored' => 'Restaurada',
    ],
];
