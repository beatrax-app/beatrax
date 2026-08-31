<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Bez kategorii',
    'unavailable_category' => 'Kategoria niedostępna na tym urządzeniu',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Rozliczona',
        'uncleared' => 'Nierozliczona',
        'reconciled' => 'Uzgodniona',
    ],

    'badge' => [

        'reconciled_hint' => 'Uzgodniona — najpierw cofnij uzgodnienie, aby zmienić status.',
        'toggle_aria' => ':label — kliknij, aby przełączyć',
        // i18n-review: pl · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — dotknij, aby przełączyć',
    ],
];
