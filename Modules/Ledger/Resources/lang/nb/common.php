<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Ikke kategorisert',
    'unavailable_category' => 'Kategorien finnes ikke på denne enheten',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Bokført',
        'uncleared' => 'Ikke bokført',
        'reconciled' => 'Avstemt',
    ],

    'badge' => [

        'reconciled_hint' => 'Avstemt — opphev avstemmingen først for å endre status.',
        'toggle_aria' => ':label — klikk for å veksle',
        // i18n-review: nb · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — trykk for å veksle',
    ],
];
