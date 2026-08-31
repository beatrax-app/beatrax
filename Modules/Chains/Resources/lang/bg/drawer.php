<?php

declare(strict_types=1);

return [
    'heading_named' => 'Верига за :name',
    'heading' => 'Верига',

    'unresolved_heading' => 'Веригата още не е разрешена',
    'unresolved_body' => 'Разрешаването на вериги още работи. Отвори опашката за преглед или опресни след малко.',

    'none_heading' => 'Не е намерена верига на финансиране',
    'none_body' => 'За тази транзакция не е открита верига на финансиране. Ако си очаквал такава, подай кандидат от опашката за преглед.',

    'none_beyond_leg' => 'Няма верига на финансиране след този участък.',

    'covers_charges' => 'Покрива :count плащане по ICS|Покрива :count плащания по ICS',
    'show_more_fanout' => 'Покажи още :count · :shown от :total',

    'confirm' => 'Потвърди',
    'reject' => 'Отхвърли',
    'confirm_aria' => 'Потвърди връзката във веригата :id',
    'reject_aria' => 'Отхвърли връзката във веригата :id',

    'confidence_tier' => [
        'deterministic' => 'Детерминистично',
        'confirmed' => 'Потвърдено',
        'candidate' => 'Кандидат',
    ],

    'confidence_aria' => [
        'deterministic' => 'Увереност: детерминистично съвпадение',
        'confirmed' => 'Увереност: потвърдено',
        'candidate' => 'Увереност: кандидат; изисква преглед',
    ],
];
