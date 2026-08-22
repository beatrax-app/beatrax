<?php

declare(strict_types=1);

return [
    'page_title' => 'Vista previa de la importación',
    'heading' => 'Vista previa de la importación',
    'discard' => 'Descartar la importación',
    'confirm' => 'Confirmar la importación',
    'subtitle' => 'Revisa las filas analizadas. No se guarda nada en tu libro mayor hasta que confirmes.',

    'expired_html' => 'La vista previa ha caducado. <a href="/imports/new" class="underline">Vuelve a subir el archivo</a> para intentarlo de nuevo.',

    'save_name' => 'Guardar el nombre',
    'account_name_label' => 'Nombre de la cuenta',
    'account_placeholder' => 'p. ej. Cuenta de ahorro principal',
    'rename_aria' => 'Cambiar el nombre de esta contraparte',

    'unknown_iban_prefix' => 'Hemos encontrado un IBAN desconocido:',
    'unknown_iban_suffix' => 'Ponle nombre a esta cuenta.',

    'ics' => [
        'heading' => 'Ponle nombre a tu cuenta de tarjeta ICS.',
        'help' => 'Es la primera vez que importas datos de ICS. Ponle un nombre a esta tarjeta para que aparezca igual en toda la app.',
        'placeholder' => 'p. ej. Tarjeta ICS',
    ],

    'paypal' => [
        'heading' => 'Ponle nombre a tu cuenta de PayPal.',
        'help' => 'Es la primera vez que importas datos de PayPal. Ponle un nombre a este monedero para que aparezca igual en toda la app.',
        'placeholder' => 'p. ej. PayPal',
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

    'chain' => [
        'heading' => 'Resolviendo cadenas…',
        'pending' => 'En cola. El resolutor de cadenas empezará en breve.',
        'running' => 'Enlazando cadenas de financiación y descomponiendo liquidaciones del extracto.',
        'failed_prefix' => 'La resolución de cadenas ha fallado:',
        'failed_detail' => 'los detalles están en el registro de trabajos',
        'open_horizon' => 'Abre Horizon',
        'failed_suffix' => 'para reintentarlo o revisarlo.',
    ],

    'errors' => [
        'app_locked' => 'Desbloquea la aplicación para importar: las claves de cifrado no se pueden usar mientras está bloqueada.',
        'file_unreadable' => 'No se ha podido leer este archivo.',
        'iban_not_in_preview' => 'Este IBAN no forma parte de la vista previa actual.',
        'row_unreadable' => 'No se ha podido leer esta fila.',
        'unknown_account' => 'Esta fila pertenece a una cuenta a la que aún no has puesto nombre.',
    ],

    'failed' => [
        'heading' => 'No se ha podido leer este archivo',
        'no_rows' => 'No se han encontrado transacciones en este archivo, así que no hay nada que importar.',
        'nothing_read' => 'Nada de este archivo se ha podido leer como transacción, así que no hay nada que importar.',
        'every_row' => 'Ninguna fila de este archivo se ha podido leer, así que no hay nada que importar. Cada una aparece abajo con su motivo.',
        'likely_cause' => 'Lo habitual es que la fila de cabecera no coincida con el origen que elegiste. Revisa el banco y el formato en la pantalla de subida, o vuelve a descargar el extracto de tu banco.',
        'truncated_heading' => 'Solo se ha podido leer parte de este archivo',
        'truncated' => 'La lectura se detuvo a mitad del archivo. Todo lo posterior no se ha leído y no se importará.',
        'some_rows' => 'Algunas filas no se han podido leer. Están marcadas abajo y se omitirán; al confirmar se importa el resto.',
        'detail_label' => 'Lo que informó el analizador:',
        'rows_read_label' => 'Filas leídas',
        'rows_skipped_label' => 'Filas omitidas',
    ],
];
