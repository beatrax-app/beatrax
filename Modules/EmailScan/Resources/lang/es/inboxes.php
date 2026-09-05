<?php

declare(strict_types=1);

return [
    'heading' => 'Bandejas de entrada',
    'intro' => 'Conecta bandejas de entrada de Gmail y Microsoft 365 para que Beatrax pueda buscar recibos en ellas.',
    'intro_phone' => 'El análisis de bandejas se hace en la aplicación de escritorio, no en este teléfono.',

    'phone_heading' => 'Este teléfono no analiza buzones',
    'phone_body' => 'Conecta Gmail o Microsoft 365 en la aplicación de escritorio: los recibos que encuentre llegan aquí por sincronización.',
    'connection_canceled' => 'Conexión cancelada.',
    'connection_failed' => 'No se ha podido completar la conexión.',

    'backfilling' => 'Recuperando historial',
    'backfill_progress' => ':fetched / ~:count mensaje|:fetched / ~:count mensajes',

    'connect_heading' => 'Conecta tu correo',
    'connect_body' => 'Importa recibos de PayPal, ICS Cards, Google Play y otros comercios dando a Beatrax acceso de solo lectura a una o varias de tus bandejas de entrada.',
    'connect_body_phone' => 'Los recibos de PayPal, ICS Cards, Google Play y otros comercios los importa la aplicación de escritorio, desde las bandejas a las que le das acceso de solo lectura. Este teléfono muestra lo que encuentra esa importación.',
    'connect_gmail' => 'Conectar Gmail',
    'connect_microsoft' => 'Conectar Microsoft 365',
    'readonly_note' => 'Beatrax solo lee mensajes. Nunca envía, etiqueta, mueve ni elimina nada en tu bandeja de entrada.',

    'months' => ':count mes|:count meses',
    'not_scanned_yet' => 'aún sin analizar',
    'not_scanned_yet_phone' => 'sin analizar en este teléfono',
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

    'error_detail' => 'El último análisis no se completó. Prueba «Analizar ahora» o vuelve a conectar este buzón.',
    'oauth_state_mismatch' => 'Ese enlace de conexión ha caducado o ya se ha utilizado. Vuelve a empezar la conexión.',
    'oauth_client_missing' => 'La configuración única para ese proveedor de correo no está terminada en este dispositivo, así que todavía no hay nada con lo que conectar. Vuelve a pulsar Conectar para terminarla.',
    'oauth_no_code' => 'Tu proveedor de correo te ha devuelto sin el código que Beatrax necesita para terminar, así que no se ha conectado ningún buzón. Vuelve a empezar la conexión.',
    'oauth_grant_refused' => 'Tu proveedor de correo ha rechazado el permiso concedido a Beatrax: ha caducado o se ha retirado. Vuelve a empezar la conexión y concédelo.',
    'oauth_exchange_failed' => 'Tu proveedor de correo no ha completado la conexión, así que no se ha añadido ningún buzón. Inténtalo otra vez dentro de unos minutos.',
    'oauth_not_saved' => 'La conexión no se ha podido guardar en este dispositivo, así que no se ha añadido ningún buzón. Inténtalo otra vez; si sigue fallando, el registro de la app anota qué la detuvo.',
    'oauth_no_offline_access_google' => 'Google no ha concedido el permiso duradero que Beatrax necesita, así que este buzón dejaría de analizarse en una hora. Publica tu pantalla de consentimiento OAuth en producción y vuelve a conectar.',
    'oauth_no_offline_access' => 'Tu proveedor de correo no ha concedido el permiso duradero que Beatrax necesita, así que este buzón dejaría de analizarse en una hora. Vuelve a conectar y permite el acceso sin conexión cuando te lo pida.',
    'oauth_no_offline_access_google_phone' => 'Google no ha concedido el permiso duradero que Beatrax necesita, así que no se conectó ningún buzón. Publica tu pantalla de consentimiento OAuth en producción y vuelve a conectar: el análisis en sí se hace en la aplicación de escritorio.',
    'oauth_no_offline_access_phone' => 'Tu proveedor de correo no ha concedido el permiso duradero que Beatrax necesita, así que no se conectó ningún buzón. Vuelve a conectar y permite el acceso sin conexión cuando te lo pida: el análisis en sí se hace en la aplicación de escritorio.',

    'retry_seconds' => 'reintento en :ns',
    'retry_minutes' => 'reintento en :nmin',
    'retry_hours' => 'reintento en :nh',

    'reconnect' => 'Volver a conectar',
    'disconnect' => 'Desconectar',
    'disconnect_confirm' => '¿Desconectar :email? Esto elimina las credenciales guardadas de este buzón, su historial de análisis y los remitentes que hayas añadido o descartado. Los recibos ya registrados en Beatrax no se ven afectados. Volver a conectarlo inicia un análisis desde cero.',
    'scan_now' => 'Analizar ahora',
    'scan_in_progress_title' => 'Ya hay un análisis en curso',

    'add_another' => 'Añadir otra bandeja de entrada',
    'gmail_card_body' => 'Conecta una cuenta de Gmail para que Beatrax pueda buscar recibos en ella.',
    'microsoft_card_body' => 'Conecta una cuenta de Microsoft 365 o de Outlook.com para que Beatrax pueda buscar recibos en ella.',
    'gmail_card_body_phone' => 'Gmail lo analiza la aplicación de escritorio. Una cuenta conectada aquí nunca se analiza por su cuenta.',
    'microsoft_card_body_phone' => 'Microsoft 365 y Outlook.com los analiza la aplicación de escritorio. Una cuenta conectada aquí nunca se analiza por su cuenta.',

    'discovered_heading' => 'Remitentes descubiertos',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (extractos)',
    ],
    'discovered_body' => 'Remitentes que parecen enviar recibos pero que aún no están en tu lista de recibos conocidos. Añade los que quieras que Beatrax analice; descarta el resto.',
    'last_seen' => 'visto por última vez',
    'seen_times' => 'Visto :count vez|Visto :count veces',
    'add' => 'Añadir',
    'add_aria' => 'Añadir :email',
    'dismiss' => 'Descartar',
    'dismiss_aria' => 'Descartar :email',

    'toast' => [
        'reconnect_first' => 'Vuelve a conectar esta bandeja antes de escanear.',
        'scan_in_progress' => 'Ya hay un análisis en curso.',
        'scan_started' => 'Análisis iniciado.',
        'sender_added' => 'Remitente añadido.',
        'sender_dismissed' => 'Remitente descartado.',
    ],
];
