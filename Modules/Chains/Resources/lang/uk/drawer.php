<?php

declare(strict_types=1);

return [
    'heading_named' => 'Ланцюг — :name',
    'heading' => 'Ланцюг',

    'unresolved_heading' => 'Ланцюг ще не розв’язано',
    'unresolved_body' => 'Розв’язувач ланцюгів ще працює. Відкрий чергу перевірки або онови сторінку за мить.',

    'none_heading' => 'Ланцюг фінансування не знайдено',
    'none_body' => 'Для цієї транзакції ланцюга фінансування не виявлено. Якщо він мав бути, подай кандидата з черги перевірки.',

    'none_beyond_leg' => 'Далі за цією ланкою ланцюга фінансування не знайдено.',

    'covers_charges' => 'Покриває :count списання ICS|Покриває :count списання ICS|Покриває :count списань ICS',
    'show_more_fanout' => 'Показати ще :count · :shown з :total',

    'confirm' => 'Підтвердити',
    'reject' => 'Відхилити',
    'confirm_aria' => 'Підтвердити ланку ланцюга :id',
    'reject_aria' => 'Відхилити ланку ланцюга :id',

    'confidence_tier' => [
        'deterministic' => 'Детермінований',
        'confirmed' => 'Підтверджено',
        'candidate' => 'Кандидат',
    ],

    'confidence_aria' => [
        'deterministic' => 'Впевненість: детермінований збіг',
        'confirmed' => 'Впевненість: підтверджено',
        'candidate' => 'Впевненість: кандидат; потребує перевірки',
    ],
];
