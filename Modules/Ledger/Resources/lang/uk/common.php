<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Без категорії',
    'unavailable_category' => 'Категорії немає на цьому пристрої',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Проведена',
        'uncleared' => 'Не проведена',
        'reconciled' => 'Звірена',
    ],

    'badge' => [

        'reconciled_hint' => 'Звірена — спершу скасуй звірку, щоб змінити статус.',
        'toggle_aria' => ':label — натисни, щоб перемкнути',
        // i18n-review: uk · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — торкнися, щоб перемкнути',
    ],
];
