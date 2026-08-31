<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Senza categoria',
    'unavailable_category' => 'Categoria non presente su questo dispositivo',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Compensata',
        'uncleared' => 'Non compensata',
        'reconciled' => 'Riconciliata',
    ],

    'badge' => [

        'reconciled_hint' => 'Riconciliata — annulla prima la riconciliazione per cambiare stato.',
        'toggle_aria' => ':label — fai clic per cambiare',
        // i18n-review: it · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — tocca per cambiare',
    ],
];
