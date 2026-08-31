<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Sensibilidad de las alertas',
    'sensitivity_help' => 'Con qué facilidad Beatrax considera inusual un cargo para ese comercio o categoría, de 1 a 100. Más alto marca más.',

    'min_amount_label' => 'Importe mínimo del cargo',
    'min_amount_help' => 'Ignora las anomalías en cargos por debajo de este importe. Se guarda en unidades menores (:symbol) — :minor significa :example.',

    'save' => 'Guardar ajustes de anomalías',
    'saved' => 'Guardado.',

    'suppression' => [
        'summary' => 'Reglas de supresión',
        'empty' => 'Aún no hay reglas de supresión. Cuando marques un cargo como previsto, aquí aparecerá una regla.',
        'remove' => 'Quitar',
        'remove_aria' => 'Quitar regla de supresión',
        'removed_toast' => 'Regla eliminada',
    ],

    'unknown_merchant' => 'Comercio desconocido',

    'detectors' => [
        'large' => 'Cargo elevado',
        'first_time' => 'Primera vez',
        'duplicate' => 'Duplicado',
    ],

    'errors' => [
        'sensitivity_range' => 'La sensibilidad debe estar entre 1 y 100.',
        'min_amount_negative' => 'El importe mínimo del cargo no puede ser negativo.',
    ],
];
