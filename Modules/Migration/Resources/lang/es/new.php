<?php

declare(strict_types=1);

return [
    'page_title' => 'Importar desde YNAB / Actual',

    'eyebrow' => 'Migraciones',
    'heading' => 'Importar desde YNAB / Actual',
    'intro' => 'Trae tu árbol de categorías, tu historial de presupuestos y tus transacciones desde YNAB4, el nuevo YNAB o Actual Budget. No se escribe nada en tu libro mayor hasta que lo revises y lo confirmes.',
    'reconcile_context' => 'Buscando novedades respecto a tu última importación de :product.',

    'source_label' => 'Fuente',
    'file_label' => 'Archivo',
    'parse_button' => 'Analizar la exportación',

    'hints' => [
        'ynab4' => 'Exporta tu presupuesto completo como archivo ZIP desde el menú Archivo → Exportar de YNAB4.',
        'nynab' => 'Exporta tu presupuesto desde nYNAB con Archivo → Exportar presupuesto y comprime los archivos CSV resultantes en un ZIP.',
        'actual' => 'Exporta tu presupuesto como archivo ZIP desde Ajustes → Exportar datos de Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Esto no parece una exportación de YNAB4, nYNAB o Actual que podamos leer. Revisa el archivo e inténtalo de nuevo.',
        'file_too_large' => 'Ese archivo es demasiado grande para una exportación de migración.',
    ],
];
