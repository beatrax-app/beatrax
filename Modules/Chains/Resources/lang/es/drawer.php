<?php

declare(strict_types=1);

return [
    'heading_named' => 'Cadena de :name',
    'heading' => 'Cadena',

    'unresolved_heading' => 'Cadena aún sin resolver',
    'unresolved_body' => 'El resolutor de cadenas sigue en marcha. Abre la cola de revisión o actualiza dentro de un momento.',

    'none_heading' => 'No se encontró ninguna cadena de financiación',
    'none_body' => 'Esta transacción no tiene ninguna cadena de financiación detectada. Si esperabas una, envía una candidata desde la cola de revisión.',

    'none_beyond_leg' => 'No se encontró ninguna cadena de financiación más allá de este tramo.',

    'covers_charges' => 'Cubre :count cargo ICS|Cubre :count cargos ICS',
    'show_more_fanout' => 'Mostrar :count más · :shown de :total',

    'confirm' => 'Confirmar',
    'reject' => 'Rechazar',
    'confirm_aria' => 'Confirmar el enlace de cadena :id',
    'reject_aria' => 'Rechazar el enlace de cadena :id',

    'confidence_tier' => [
        'deterministic' => 'Determinista',
        'confirmed' => 'Confirmada',
        'candidate' => 'Candidata',
    ],

    'confidence_aria' => [
        'deterministic' => 'Confianza: coincidencia determinista',
        'confirmed' => 'Confianza: confirmada',
        'candidate' => 'Confianza: candidata; necesita revisión',
    ],
];
