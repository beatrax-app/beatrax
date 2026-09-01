<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Kategorizálatlan',
    'unavailable_category' => 'A kategória nincs meg ezen az eszközön',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Elszámolt',
        'uncleared' => 'Nem elszámolt',
        'reconciled' => 'Egyeztetve',
    ],

    'badge' => [

        'reconciled_hint' => 'Egyeztetve — az állapot módosításához előbb szüntesd meg az egyeztetést.',
        'toggle_aria' => ':label — kattints a váltáshoz',
        // i18n-review: hu · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — koppints a váltáshoz',
    ],
];
