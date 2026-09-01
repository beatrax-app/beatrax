<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Okategoriserat',
    'unavailable_category' => 'Kategorin finns inte på den här enheten',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Bokförd',
        'uncleared' => 'Ej bokförd',
        'reconciled' => 'Avstämd',
    ],

    'badge' => [

        'reconciled_hint' => 'Avstämd — häv avstämningen först för att ändra status.',
        'toggle_aria' => ':label — klicka för att växla',
        // i18n-review: sv · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — tryck för att växla',
    ],
];
