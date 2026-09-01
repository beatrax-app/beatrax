<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Ikke kategoriseret',
    'unavailable_category' => 'Kategori findes ikke på denne enhed',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Bogført',
        'uncleared' => 'Ikke bogført',
        'reconciled' => 'Afstemt',
    ],

    'badge' => [

        'reconciled_hint' => 'Afstemt — ophæv afstemningen først for at ændre status.',
        'toggle_aria' => ':label — klik for at skifte',
        // i18n-review: da · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — tryk for at skifte',
    ],
];
