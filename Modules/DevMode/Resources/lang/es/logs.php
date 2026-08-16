<?php

declare(strict_types=1);

return [
    'heading' => 'Registros',
    'subtitle' => 'Seguimiento en directo del archivo de log de Laravel de hoy, con doble censura de datos al escribir y al transmitir.',
    'truncate' => 'Vaciar',
    'truncate_confirm' => '¿Vaciar el archivo de log de hoy? Esto no se puede deshacer.',
    'truncate_title' => 'Vacía el archivo de log de hoy (conserva el inodo para que el seguimiento se reanude sin problemas)',
    'filters_aria' => 'Filtros de log',
    'severity_aria' => 'Filtro de gravedad',
    'channel_placeholder' => 'Filtro de canal…',
    'channel_aria' => 'Filtro de canal',
    'contains_placeholder' => 'Buscar en lo visible…',
    'contains_aria' => 'Filtro «contiene»',
    'pause' => 'Pausar',
    'resume' => 'Reanudar',
    'waiting' => 'Esperando líneas de log…',
    'copy' => 'Copiar',
    'copy_title' => 'Copiar la entrada completa',
    'copy_title_copied' => 'Copiado',
    'copy_aria' => 'Copiar la entrada de log',
    'copy_aria_copied' => 'Copiado al portapapeles',
    'dismiss' => 'Ocultar',
    'dismiss_title' => 'Ocultar de la vista (no modifica el archivo de log)',
    'dismiss_aria' => 'Ocultar la entrada de log de la vista',
    'totals' => [
        'showing' => 'Mostrando',
        'of' => 'de',
        'received' => 'recibidas (búfer máx. 10k)',
        'lines_today' => 'líneas hoy',
        'today' => 'hoy',
        'across' => 'repartidas en',
        'daily_files' => 'archivos diarios',
    ],

    'status' => [
        'poll_interrupted' => 'Sondeo de logs interrumpido. Reintentando…',
        'paused' => 'En pausa.',
        'copy_failed_prefix' => 'Error al copiar: ',
        'clipboard_unavailable' => 'portapapeles no disponible',
    ],

    'toast' => [
        'truncated' => 'Log vaciado — se han liberado :size.',
        'nothing' => 'Nada que vaciar.',
    ],
];
