<?php

declare(strict_types=1);

return [
    'page_title' => 'Importación completada',
    'heading' => 'Importación completada',

    'summary' => 'Se han importado :inserted transacciones · se han omitido :duplicates duplicados',
    'summary_enriched' => ' · :count enriquecidas',
    'summary_errors' => ' · :count errores',

    'show_duplicates' => 'Mostrar los duplicados omitidos (:count)',
    'duplicates_help' => 'Los duplicados son filas que ya están en tu libro mayor — se omiten sin avisar al volver a importar.',
    'show_errors' => 'Mostrar los errores (:count)',
    'errors_help' => 'Los errores son filas que no se han podido analizar; no se han añadido a tu libro mayor.',

    'upload_another' => 'Subir otro extracto',
];
