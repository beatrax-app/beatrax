<?php

declare(strict_types=1);

return [
    'page_title' => 'Контрагенти',
    'heading' => 'Контрагенти',

    'entities' => ':count обект|:count обекта',
    'need_identification' => ':count изискват разпознаване',

    'search_placeholder' => 'Търси по име, псевдоним или IBAN…',
    'search_aria' => 'Търсене на контрагенти',
    'sort' => 'Подредба: Общо за 12 мес. ↓',

    'view_mode' => 'Изглед',
    'view_cards' => 'Карти',
    'view_list' => 'Списък',

    'filter_aria' => 'Филтрирай по вид',
    'chips' => [
        'all' => 'Всички',
        'merchant' => 'Търговци',
        'personal' => 'Лични',
        'bank' => 'Банки',
        'government' => 'Държавни институции',
        'self' => 'Собствени сметки',
        'unknown' => 'Неизвестни',
    ],

    'empty_heading' => 'Още няма контрагенти',
    'empty_body' => 'Контрагентите се появяват тук автоматично, докато импортираш транзакции. Импортирай извлечение, за да започнеш.',
    'empty_cta' => 'Импортирай извлечение →',

    'self_routing' => 'Само прехвърляне',
    'self_no_flow' => 'без разходи / без приходи',
    'self_open' => 'Отвори сметката →',

    'label_this' => 'Етикетирай този контрагент',

    'stat_12mo' => '12 мес.',
    'stat_net_received' => 'Нетно получено',
    'stat_avg_mo' => 'Средно / мес.',
    'sparkline_aria' => 'Графика на активността за 12 месеца',

    'table_name' => 'Име',
    'table_type' => 'Вид',
    'table_12mo' => '12 мес.',
    'table_avg' => 'Средно / мес.',
];
