<?php

declare(strict_types=1);

return [
    'page_title' => 'Ланцюги',
    'heading' => 'Ланцюги',
    'review_link' => 'Черга перевірки →',
    'hints_link' => 'Підказки →',
    'subtitle' => 'Покупки, зібрані в одне списання. На кожній картці — одне списання та платежі, які до нього увійшли.',

    'empty_heading' => 'Ланцюгів ще немає',
    'empty_body' => 'Імпортуй кілька виписок (банк, PayPal, картка) — і розв’язувач автоматично покаже тут міжрахункові ланцюги.',

    'no_counterparty' => '(без контрагента)',
    'leg_count' => ':count платіж|:count платежі|:count платежів',
    'legs_more' => '+ ще :count',
    'state_aria' => 'Стан: :state',

    'state' => [
        'candidate' => 'Кандидат',
        'confirmed' => 'Підтверджено',
        'rejected' => 'Відхилено',
    ],

    'kind' => [
        'paypal_funding' => 'Поповнення PayPal',
        'ics_bulk_settle' => 'Групове врегулювання iDEAL',
        'funded_by_card_hint' => 'Профінансовано карткою (підказка)',
        'refund_of_hint' => 'Повернення коштів (підказка)',
    ],
];
