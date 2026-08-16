<?php

declare(strict_types=1);

return [
    'page_title' => 'Subir extracto',
    'heading' => 'Subir extracto',
    'migrate_prompt' => '¿Vienes de otra app de presupuestos?',
    'migrate_link' => 'Importar desde YNAB o Actual',
    'subtitle' => 'Suelta aquí una exportación de banco, de tarjeta o de PayPal, o un archivo de recibo por correo.',
    'mime_hint' => 'Ese archivo no parece una exportación de extracto compatible. Suelta un CSV bancario, un MT940 (.sta / .mt940 / .txt), un XML CAMT.053, un PDF de extracto de tarjeta, un mensaje de correo (.eml) o un archivo de buzón (.mbox).',

    'source_label' => 'Fuente',

    'issuer_other_bank' => 'Otro banco (N26, Revolut, ING…)',
    'issuer_email_file' => 'Archivo de correo (.eml, .mbox)',

    'format_label' => 'Formato',
    'file_label' => 'Archivo',
    'submit' => 'Subir extracto',

    'formats' => [
        'activity_download' => 'Descarga de actividad (CSV)',
        'email_message' => 'Mensaje de correo (.eml)',
        'mailbox_archive' => 'Archivo de buzón (.mbox)',
        'ing_nl' => 'ING Países Bajos (CSV)',
    ],

    'errors' => [
        'file_max' => 'Ese archivo es demasiado grande. Suelta una exportación de extracto que no supere el límite de tamaño del formato elegido.',
        'file_extensions' => 'Ese archivo no parece una exportación de extracto compatible. Suelta un CSV bancario, un MT940 (.sta / .mt940 / .txt), un XML CAMT.053, un PDF de extracto de tarjeta, un mensaje de correo (.eml) o un archivo de buzón (.mbox).',
        'issuer_format' => 'El valor :attribute no es válido para la fuente :source.',
        'process_failed' => 'No se ha podido procesar este archivo (:class). El error completo está en /dev/logs.',
    ],
];
