<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Календар',
        'subtitle' => 'Майбутні платежі та прогнозований щоденний баланс.',
    ],

    'summary' => [
        'computing' => 'Прогноз оновлюється…',
        'risk' => 'Баланс опускається нижче :zero протягом :count дня — перший: :date.|Баланс опускається нижче :zero протягом :count днів — перший: :date.|Баланс опускається нижче :zero протягом :count днів — перший: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Попередній місяць',
        'next_month' => 'Наступний місяць',
        'accounts' => 'Рахунки',
        'popover_aria' => 'Налаштування показу рахунків',
        'no_accounts' => 'Рахунків не знайдено.',
        'col_account' => 'Рахунок',
        'col_entries' => 'Записи',
        'col_balance' => 'Баланс',
        'show_entries_aria' => 'Показати записи — рахунок :name',
        'count_balance_aria' => 'Враховувати в балансі — рахунок :name',
    ],

    'empty' => [
        'heading' => 'Майбутніх платежів немає',
        'body' => 'Підключи рахунок або затверди регулярну серію, щоб побачити прогнозовані платежі в календарі.',
        'review' => 'Переглянути регулярні →',
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
        'aria' => 'Календар: :month',
    ],

    'cell' => [
        'entry' => 'запис|записи|записів',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', прогнозований баланс мінус :amount',
        'aria_balance_positive' => ', прогнозований баланс :amount',
        'overflow' => 'ще +:count',
        'paid' => 'Оплачено',
        'missed' => 'Очікувано — не знайдено',
    ],

    'entry' => [
        'booked_unnamed' => 'Проведений платіж',
    ],

    'balance' => [
        'not_counted' => '· :list не враховується — тамтешні платежі не змінюють баланс',
    ],

    'panel' => [
        'aria' => 'Панель деталей дня',
        'close' => 'Закрити панель дня',
        'close_caption' => 'Закрити',
        'start_of_day' => 'Початок дня',
        'no_payments' => 'Цього дня платежів немає.',
        'date_approximate' => '~ дата приблизна',
        'series' => '↗ серія',
        'counterparty' => '↗ контрагент',
        'transaction' => '↗ транзакція',
        'end_of_day' => 'Кінець дня',
    ],
];
