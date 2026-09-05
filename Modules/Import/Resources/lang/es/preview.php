<?php

declare(strict_types=1);

return [
    'page_title' => 'Vista previa de la importación',
    'heading' => 'Vista previa de la importación',
    'discard' => 'Descartar la importación',
    'confirm' => 'Confirmar la importación',
    'subtitle' => 'Revisa las filas analizadas. No se guarda nada en tu libro mayor hasta que confirmes.',

    'already_imported' => 'Este archivo ya se ha importado.',

    'already_imported_link' => 'Ver el resultado de la importación',

    'expired_html' => 'La vista previa ha caducado. <a href="/imports/new" class="underline">Vuelve a subir el archivo</a> para intentarlo de nuevo.',
    'unreadable_html' => 'No se puede leer la vista previa. <a href="/imports/new" class="underline">Vuelve a subir el archivo</a> para intentarlo de nuevo.',

    'save_name' => 'Guardar el nombre',
    'account_name_label' => 'Nombre de la cuenta',
    'account_placeholder' => 'p. ej. Cuenta de ahorro principal',
    'rename_aria' => 'Cambiar el nombre de esta contraparte',

    'unknown_iban_prefix' => 'Hemos encontrado un IBAN desconocido:',

    'unknown_account_prefix' => 'Hemos encontrado una cuenta desconocida:',
    'unknown_iban_suffix' => 'Ponle nombre a esta cuenta.',

    'ics' => [
        'name' => 'Tarjeta ICS',
        'heading' => 'Ponle nombre a tu cuenta de tarjeta ICS.',
        'help' => 'Es la primera vez que importas datos de ICS. Ponle un nombre a esta tarjeta para que aparezca igual en toda la app.',
        'placeholder' => 'p. ej. Tarjeta ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Ponle nombre a tu cuenta de PayPal.',
        'help' => 'Es la primera vez que importas datos de PayPal. Ponle un nombre a este monedero para que aparezca igual en toda la app.',
        'placeholder' => 'p. ej. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Ponle nombre a tu cuenta de Google Play.',
        'help' => 'Es la primera vez que importas un recibo de Google Play. Ponle un nombre a esta cuenta para que aparezca igual en toda la app.',
        'placeholder' => 'p. ej. Google Play',
    ],

    'col_date' => 'Fecha',
    'col_funding_source' => 'Origen de los fondos',
    'col_counterparty' => 'Contraparte',
    'col_amount' => 'Importe',
    'col_status' => 'Estado',

    'status' => [
        'new' => 'Nueva',
        'new_title' => 'Se añadirá a tu libro mayor.',
        'duplicate' => 'Duplicada',
        'duplicate_title' => 'Ya importada — se omitirá.',
        'enriched' => 'Enriquecida',
        'enriched_title' => 'La fila existente se actualizará con una referencia de origen más fiable.',
        'error' => 'Error',
    ],

    'rows_shown' => 'Filas mostradas: :shown de :total',

    'show_more' => 'Mostrar más filas',

    'errors' => [
        'app_locked' => 'Desbloquea la aplicación para importar: las claves de cifrado no se pueden usar mientras está bloqueada.',
        'archive_holds_one_message' => 'Este archivo es un solo mensaje de correo, no un archivo de buzón, así que leído como buzón no tiene nada dentro. Súbelo otra vez con el formato en Mensaje de correo.',
        'email_file_is_an_archive' => 'Este archivo es un archivo de buzón: contiene más de un mensaje, y leído como un solo mensaje solo tomaría el primero. Súbelo otra vez con el formato en Archivo de buzón.',
        'file_stopped_short' => 'La fila de encabezado coincidía, así que el formato es correcto. La lectura se detuvo antes del final del archivo. Lo provoca una sola fila ilegible, y también un archivo demasiado grande para este dispositivo. Prueba con un periodo más corto.',
        'file_unreadable' => 'No se ha podido leer este archivo.',
        'file_unreadable_detail' => 'La aplicación no ha podido leer este archivo (:code). Los detalles completos están en el registro de la aplicación; cita este código si informas de un problema.',
        'iban_not_in_preview' => 'Este IBAN no forma parte de la vista previa actual.',
        'message_unreadable' => 'No se ha podido leer este mensaje, así que se ha omitido.',
        'not_an_email_file' => 'Este archivo no es ni un mensaje de correo ni un archivo de buzón, así que no hay nada en él que leer como recibo. Elige el tipo de importación y el formato que coincidan con tu archivo.',
        'pdf_has_no_text_layer' => 'Este PDF no contiene texto: es un escaneo o una foto de un extracto, así que no hay nada que leer en él. Descarga el extracto en sí de tu banco, o usa una exportación CSV.',
        'pdf_password_protected' => 'Este PDF está protegido con contraseña, así que ningún lector puede abrirlo. Guarda una copia sin protección desde tu visor de PDF e importa esa.',
        'pdf_reader_unavailable' => 'Esta versión de la aplicación no tiene ningún lector de PDF, así que aquí no se puede abrir un extracto en PDF. Importa este archivo en otro dispositivo, o usa una exportación CSV de tu banco.',
        'row_belongs_to_another_statement' => 'Esta fila pertenece a una transacción de otro archivo de extracto. Importa también ese extracto: los dos se leen juntos.',
        'row_unreadable' => 'No se ha podido leer esta fila.',
        'row_unreadable_detail' => 'La aplicación no ha podido leer esta fila (:code). Los detalles completos están en el registro de la aplicación; cita este código si informas de un problema.',
        'unknown_account' => 'Esta fila pertenece a una cuenta a la que aún no has puesto nombre.',
    ],

    'refused' => [
        'accounts_to_name' => 'Este archivo espera a que pongas nombre a la cuenta a la que pertenecen sus filas.',
        'file_did_not_read_in_full' => 'Este archivo no se ha podido leer hasta el final.',
        'nothing_importable' => 'No hay nada en este archivo que se pueda importar.',
        'preview_expired' => 'La vista previa de este archivo es demasiado antigua para guardarla ahora. Vuelve a subirlo.',
    ],

    'receipts' => [
        'heading' => 'Este archivo se ha leído como correo',
        'saved' => 'Lo que traía está abajo, y cada mensaje se ha guardado.',
        'none_imported' => 'Nada de esto se ha convertido en transacción, así que no se ha añadido nada a tu libro mayor.',
        'shown' => 'Mensajes mostrados: :shown de :total',
        'no_subject' => 'Sin asunto',

        'state' => [
            'read' => 'Leído como pago — confirma esta importación para añadirlo a tu libro mayor.',
            'not_a_payment' => 'No es un pago. Este mensaje anuncia algo en lugar de confirmar un pago.',
            'unreadable' => 'Guardado. La app lee recibos de este remitente, pero no ha encontrado importe, comercio ni referencia en el mensaje.',
            'unknown_sender' => 'Guardado. La app no lee recibos de este remitente, así que no ha tomado nada del mensaje.',
        ],
    ],

    'failed' => [
        'heading' => 'No se ha podido leer este archivo',
        'no_rows' => 'No se han encontrado transacciones en este archivo, así que no hay nada que importar.',
        'nothing_read' => 'Nada de este archivo se ha podido leer como transacción, así que no hay nada que importar.',
        'every_row' => 'Ninguna fila de este archivo se ha podido leer, así que no hay nada que importar. Cada una aparece abajo con su motivo.',
        'likely_cause' => 'Lo habitual es que la fila de cabecera no coincida con el origen que elegiste. Revisa el banco y el formato en la pantalla de subida, o vuelve a descargar el extracto de tu banco.',
        'truncated_heading' => 'Solo se ha podido leer parte de este archivo',
        'truncated' => 'La lectura se detuvo a mitad del archivo. Este archivo no se puede importar: guardar solo la parte leída dejaría ausente el resto del periodo, sin nada que lo indique.',
        'truncated_action' => 'Vuelve a subir el archivo o descarga una copia nueva del extracto de tu banco.',
        'some_rows' => 'Algunas filas no se han podido leer. Están marcadas abajo y se omitirán; al confirmar se importa el resto.',
        'detail_label' => 'Lo que informó el analizador:',
        'rows_read_label' => 'Filas leídas',
        'rows_skipped_label' => 'Filas omitidas',
    ],
];
