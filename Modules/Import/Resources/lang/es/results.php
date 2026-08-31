<?php

declare(strict_types=1);

return [
    'page_title' => 'Importación completada',
    'heading' => 'Importación completada',

    'summary' => 'Se ha importado :count transacción|Se han importado :count transacciones',
    'summary_duplicates' => ' · se ha omitido :count duplicado| · se han omitido :count duplicados',
    'summary_enriched' => ' · :count enriquecidas',
    'summary_errors' => ' · :count error| · :count errores',

    'show_duplicates' => 'Mostrar los duplicados omitidos (:count)',
    'duplicates_help' => 'Los duplicados son filas que ya están en tu libro mayor — se omiten sin avisar al volver a importar.',
    'show_errors' => 'Mostrar los errores (:count)',
    'errors_help' => 'Los errores son filas que no se han podido analizar; no se han añadido a tu libro mayor.',

    'upload_another' => 'Subir otro extracto',

    'chain' => [
        'heading' => 'Resolviendo cadenas…',
        'pending' => 'En cola. El resolutor de cadenas empezará en breve.',
        'running' => 'Enlazando cadenas de financiación y descomponiendo liquidaciones del extracto.',
    ],

    'issues' => [
        'row' => 'Fila :row: :reason',
        'file_stopped' => 'El archivo no se ha podido leer más allá de la fila :row. Nada posterior a esa fila se ha importado.',
        'file_none' => 'El archivo no se ha podido leer en absoluto.',
        'detail' => 'El lector informó: :reason',
        'duplicate' => 'La fila :row ya estaba en tu libro mayor.',
        'more' => '+ :count sin listar',
    ],
];
