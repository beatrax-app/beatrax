<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Luokittelematon',
    'unavailable_category' => 'Luokkaa ei ole tällä laitteella',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Kuitattu',
        'uncleared' => 'Kuittaamaton',
        'reconciled' => 'Täsmäytetty',
    ],

    'badge' => [

        'reconciled_hint' => 'Täsmäytetty — pura täsmäytys ennen tilan muuttamista.',
        'toggle_aria' => ':label — vaihda napsauttamalla',
        // i18n-review: fi · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — vaihda napauttamalla',
    ],
];
