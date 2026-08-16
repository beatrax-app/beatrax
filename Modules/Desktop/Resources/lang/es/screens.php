<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Bienvenida',
        'heading' => 'Te damos la bienvenida a Beatrax',
        'subtitle' => 'Tu panel de finanzas, solo en local, está listo. Crea tu primera cuenta para empezar.',
        'get_started' => 'Empezar',
    ],

    'setup' => [
        'page_title' => 'Preparando…',
        'pending_heading' => 'Preparando…',
        'pending_body' => 'Beatrax está preparando tus datos. Solo tarda un momento.',
        'failed_body' => 'La preparación no ha podido terminar. Reinicia Beatrax; si sigue fallando, el motivo está en el registro.',
        'ready_heading' => 'Listo',
        'ready_body' => 'Preparación completada. Continuando…',
    ],

    'staging' => [
        'page_title' => 'Archivo recibido',
        'heading_prefix' => 'Archivo recibido: ',
        'button_label' => 'Iniciar la importación',
        'csv_subtitle' => 'Una exportación de un banco o de PayPal: inicia la importación para ver una vista previa y confirmarla.',
        'eml_subtitle' => 'Un recibo por correo: inicia la importación para adjuntarlo a su transacción.',
        'empty_heading' => 'No hemos podido abrir ese archivo',
        'empty_body' => 'Beatrax no ha podido leer el archivo que has abierto. Prueba a importarlo desde la página de Importaciones.',
        'open_imports' => 'Abrir Importaciones',
    ],

    'close' => [
        'title' => '¿Mantener Beatrax en marcha?',
        'body' => 'Al cerrar la ventana puedes salir de Beatrax por completo o dejarlo funcionando en segundo plano en la barra de menús para que sigan ejecutándose los análisis de correo programados.',
        'button_quit' => 'Salir de Beatrax',
        'button_keep_in_tray' => 'Dejarlo en la bandeja',
        'checkbox_remember' => 'Recordar mi elección',
    ],
];
