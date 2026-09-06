<?php

declare(strict_types=1);

return [
    'heading' => 'Sugerir una correspondencia',
    'intro' => 'Abre GitHub en tu navegador con la sugerencia ya rellenada. Solo van con ella el patrón, el nombre, la categoría y la región de arriba — y el patrón es la descripción tal como la escribió tu extracto. Tu nombre y tu correo nunca salen de este dispositivo.',

    'pattern' => 'Patrón',
    'name' => 'Nombre legible',
    'name_placeholder' => 'p. ej. Albert Heijn',
    'category' => 'Categoría (opcional)',
    'category_placeholder' => 'p. ej. Supermercado',
    'region' => 'Región',

    'regions' => [
        'other' => 'Otra',
    ],

    'yaml_preview' => 'Vista previa del YAML',

    'cancel' => 'Cancelar',
    'submit' => 'Abrir en GitHub',

    'toast' => 'Sugerencia abierta en tu navegador.',

    'errors' => [
        'pattern_required' => 'El patrón es obligatorio.',
        'name_required' => 'El nombre es obligatorio.',
        'browser_refused' => 'No se ha podido abrir tu navegador, así que no se ha enviado nada y nada ha salido de este dispositivo. Inténtalo otra vez o copia tú mismo la vista previa YAML de arriba en una pull request.',
    ],
];
