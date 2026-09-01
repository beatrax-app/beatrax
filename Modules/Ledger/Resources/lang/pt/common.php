<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Sem categoria',
    'unavailable_category' => 'Categoria não está neste dispositivo',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Compensada',
        'uncleared' => 'Por compensar',
        'reconciled' => 'Reconciliada',
    ],

    'badge' => [

        'reconciled_hint' => 'Reconciliada — anula a reconciliação para mudares o estado.',
        'toggle_aria' => ':label — clica para alternar',
        // i18n-review: pt · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — toca para alternar',
    ],
];
