<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Календар',
        'subtitle' => 'Предстоящи плащания и прогнозното ти дневно салдо.',
    ],

    'summary' => [
        'computing' => 'Прогнозата се обновява…',
        'risk' => 'Салдото пада под :zero на :date.|Салдото пада под :zero в :count дни — първо на :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Предишен месец',
        'next_month' => 'Следващ месец',
        'accounts' => 'Сметки',
        'popover_aria' => 'Настройки за показване на сметките',
        'no_accounts' => 'Няма намерени сметки.',
        'col_account' => 'Сметка',
        'col_entries' => 'Записи',
        'col_balance' => 'Салдо',
        'show_entries_aria' => 'Покажи записите за :name',
        'count_balance_aria' => 'Включи :name в салдото',
    ],

    'empty' => [
        'heading' => 'Няма предстоящи плащания',
        'body' => 'Свържи сметка или одобри повтаряща се поредица, за да видиш прогнозните плащания в календара.',
        'review' => 'Прегледай повтарящите се →',
    ],

    'weekdays' => [
        'mon' => 'Пн',
        'tue' => 'Вт',
        'wed' => 'Ср',
        'thu' => 'Чт',
        'fri' => 'Пт',
        'sat' => 'Сб',
        'sun' => 'Нд',
    ],

    'grid' => [
        'aria' => 'Календар за :month',
    ],

    'cell' => [
        'entry' => 'запис|записа',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', прогнозно салдо минус :amount',
        'aria_balance_positive' => ', прогнозно салдо :amount',
        'overflow' => '+:count още',
        'paid' => 'Платено',
        'missed' => 'Очаквано — не е намерено',
    ],

    'entry' => [
        'booked_unnamed' => 'Осчетоводено плащане',
    ],

    'balance' => [
        'not_counted' => '· :list не се брои — плащанията там не променят салдото',
    ],

    'panel' => [
        'aria' => 'Панел с детайли за деня',
        'close' => 'Затвори панела за деня',
        'close_caption' => 'Затвори',
        'start_of_day' => 'Начало на деня',
        'no_payments' => 'Няма плащания в този ден.',
        'date_approximate' => '~ приблизителна дата',
        'series' => '↗ поредица',
        'counterparty' => '↗ контрагент',
        'transaction' => '↗ транзакция',
        'end_of_day' => 'Край на деня',
    ],
];
