<?php

declare(strict_types=1);

return [
    'page_title' => 'Subir extracto',
    'heading' => 'Subir extracto',
    'migrate_prompt' => '¿Vienes de otra app de presupuestos?',
    'migrate_link' => 'Importar desde YNAB o Actual',
    'subtitle' => 'Suelta aquí un extracto en CSV, CAMT.053, MT940 o PDF, o un archivo de recibo por correo.',
    'mime_hint' => 'Archivos admitidos: CSV bancario, MT940 (.sta / .mt940 / .txt), CAMT.053 XML, PDF de extracto de tarjeta, mensaje de correo (.eml) o archivo de buzón (.mbox).',

    'type_label' => 'Tipo de importación',

    'types' => [
        'csv' => 'Archivo CSV',
        'camt053' => 'Extracto CAMT.053 (XML)',
        'mt940' => 'Extracto MT940',
        'pdf' => 'Extracto de tarjeta (PDF)',
        'email' => 'Archivo de recibo por correo',
    ],

    'format_label' => 'Formato',

    'format_from_file' => 'El formato se ha puesto en :format para que coincida con el archivo que elegiste. Cámbialo si no es correcto.',
    'file_label' => 'Archivo',
    'submit' => 'Subir extracto',

    'formats' => [
        'activity_download' => 'Descarga de actividad (CSV)',
        'email_message' => 'Mensaje de correo (.eml)',
        'mailbox_archive' => 'Archivo de buzón (.mbox)',
    ],

    'errors' => [
        'file_max' => 'Ese archivo es demasiado grande. Suelta una exportación de extracto que no supere el límite de tamaño del formato elegido.',
        'file_extensions' => 'Ese archivo no parece una exportación de extracto compatible. Suelta un CSV bancario, un MT940 (.sta / .mt940 / .txt), un XML CAMT.053, un PDF de extracto de tarjeta, un mensaje de correo (.eml) o un archivo de buzón (.mbox).',
        'type_format' => 'El valor :attribute no es válido para el tipo de importación :type.',
        'process_failed' => 'No se ha podido procesar este archivo (:class). El error completo está en /dev/logs.',
    ],
];
