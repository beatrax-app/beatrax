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
        'unknown_error' => 'se ha producido un error desconocido',
        'open_horizon' => 'Abre Horizon',
        'failed_suffix' => 'para reintentarlo o revisarlo.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Este IBAN no forma parte de la vista previa actual.',
    ],
];
