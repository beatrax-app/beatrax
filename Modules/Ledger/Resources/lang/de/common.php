<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Nicht kategorisiert',
    'unavailable_category' => 'Kategorie nicht auf diesem Gerät',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Bestätigt',
        'uncleared' => 'Nicht bestätigt',
        'reconciled' => 'Abgeglichen',
    ],

    'badge' => [

        'reconciled_hint' => 'Abgeglichen — hebe den Abgleich zuerst auf, um den Status zu ändern.',
        'toggle_aria' => ':label — zum Umschalten klicken',
        // i18n-review: de · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — zum Umschalten tippen',
    ],
];
