<?php

declare(strict_types=1);

return [
    'page_title' => 'Контрагенти',
    'heading' => 'Контрагенти',

    'entities' => ':count суб’єкт|:count суб’єкти|:count суб’єктів',
    'need_identification' => 'Без ідентифікації: :count',

    'search_placeholder' => 'Пошук за назвою, псевдонімом або IBAN…',
    'search_aria' => 'Пошук контрагентів',
    'sort' => 'Сортування: сума за 12 міс. ↓',

    'view_mode' => 'Режим перегляду',
    'view_cards' => 'Картки',
    'view_list' => 'Список',

    'filter_aria' => 'Фільтр за типом',
    'chips' => [
        'all' => 'Усі',
        'merchant' => 'Продавці',
        'personal' => 'Приватні особи',
        'bank' => 'Банки',
        'government' => 'Державні установи',
        'self' => 'Власні',
        'unknown' => 'Невідомі',
    ],

    'empty_heading' => 'Контрагентів ще немає',
    'empty_body' => 'Контрагенти з’являються тут автоматично, коли ти імпортуєш транзакції. Імпортуй виписку, щоб почати.',
    'empty_cta' => 'Імпортувати виписку →',

    'self_routing' => 'Лише перекази',
    'self_no_flow' => 'без витрат / без надходжень',
    'self_open' => 'Відкрити рахунок →',

    'label_this' => 'Позначити цього контрагента',

    'stat_12mo' => '12 міс.',
    'stat_net_received' => 'Чисті надходження',
    'stat_avg_mo' => 'Сер. / міс.',
    'sparkline_aria' => 'Графік активності за 12 місяців',

    'table_name' => 'Назва',
    'table_type' => 'Тип',
    'table_12mo' => '12 міс.',
    'table_avg' => 'Сер. / міс.',
];
