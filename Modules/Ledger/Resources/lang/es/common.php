<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Sin categorizar',
    'unavailable_category' => 'Categoría no disponible en este dispositivo',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Compensada',
        'uncleared' => 'Sin compensar',
        'reconciled' => 'Conciliada',
    ],

    'badge' => [

        'reconciled_hint' => 'Conciliada — anula la conciliación para cambiar el estado.',
        'toggle_aria' => ':label — haz clic para cambiar',
        // i18n-review: es · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — toca para cambiar',
    ],
];
