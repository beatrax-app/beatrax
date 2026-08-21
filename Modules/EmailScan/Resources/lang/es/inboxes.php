<?php

declare(strict_types=1);

return [
    'heading' => 'Bandejas de entrada',
    'intro' => 'Conecta bandejas de entrada de Gmail y Microsoft 365 para que Beatrax pueda buscar recibos en ellas.',

    'connection_canceled' => 'Conexión cancelada.',
    'connection_failed' => 'No se ha podido completar la conexión.',

    'backfilling' => 'Recuperando historial',
    'messages_suffix' => 'mensajes',

    'connect_heading' => 'Conecta tu correo',
    'connect_body' => 'Importa recibos de PayPal, ICS Cards, Google Play y otros comercios dando a Beatrax acceso de solo lectura a una o varias de tus bandejas de entrada.',
    'connect_gmail' => 'Conectar Gmail',
    'connect_microsoft' => 'Conectar Microsoft 365',
    'readonly_note' => 'Beatrax solo lee mensajes. Nunca envía, etiqueta, mueve ni elimina nada en tu bandeja de entrada.',

    'months' => ':count mes|:count meses',
    'not_scanned_yet' => 'aún sin analizar',
    'last_scanned' => 'último análisis',
    'window_prefix' => 'Periodo:',
    'edit' => 'Editar',

    'badge' => [
        'idle' => 'Inactiva',
        'backfilling' => 'Recuperando',
        'scanning' => 'Analizando',
        'rate_limited' => 'Límite de peticiones',
        'needs_reauth' => 'Requiere reautorización',
        'error' => 'Error',
    ],

    'retry_seconds' => 'reintento en :ns',
    'retry_minutes' => 'reintento en :nmin',
    'retry_hours' => 'reintento en :nh',

    'reconnect' => 'Volver a conectar',
    'disconnect' => 'Desconectar',
    'scan_now' => 'Analizar ahora',
    'scan_in_progress_title' => 'Ya hay un análisis en curso',

    'add_another' => 'Añadir otra bandeja de entrada',
    'gmail_card_body' => 'Conecta una cuenta de Gmail para que Beatrax pueda buscar recibos en ella.',
    'microsoft_card_body' => 'Conecta una cuenta de Microsoft 365 o de Outlook.com para que Beatrax pueda buscar recibos en ella.',

    'discovered_heading' => 'Remitentes descubiertos',
    'discovered_body' => 'Remitentes que parecen enviar recibos pero que aún no están en tu lista de recibos conocidos. Añade los que quieras que Beatrax analice; descarta el resto.',
    'last_seen' => 'visto por última vez',
    'seen_times' => 'Visto :count vez|Visto :count veces',
    'add' => 'Añadir',
    'add_aria' => 'Añadir :email',
    'dismiss' => 'Descartar',
    'dismiss_aria' => 'Descartar :email',

    'toast' => [
        'scan_in_progress' => 'Ya hay un análisis en curso.',
        'scan_started' => 'Análisis iniciado.',
        'sender_added' => 'Remitente añadido.',
        'sender_dismissed' => 'Remitente descartado.',
    ],
];
