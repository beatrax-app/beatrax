<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Некатегоризирани',
    'unavailable_category' => 'Категорията я няма на това устройство',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Потвърдена',
        'uncleared' => 'Непотвърдена',
        'reconciled' => 'Равнена',
    ],

    'badge' => [

        'reconciled_hint' => 'Равнена — първо премахни равнението, за да смениш статуса.',
        'toggle_aria' => ':label — щракни, за да превключиш',
        // i18n-review: bg · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — докосни, за да превключиш',
    ],
];
