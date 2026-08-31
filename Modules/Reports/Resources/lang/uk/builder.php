<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Без категорії',
    'no_counterparty' => 'Без контрагента',
    'unavailable_counterparty' => 'Контрагента немає на цьому пристрої',
    'title' => 'Звіти',
    'page_title' => 'Звіти · Beatrax',
    'subtitle' => 'Склади звіт зі свого реєстру.',
    'controls_aria' => 'Керування звітом',
    'result_aria' => 'Результат звіту',
    'dismiss' => 'Відхилити',

    'metric' => [
        'heading' => 'Показник',
        'spend' => 'Витрати',
        'income' => 'Надходження',
        'net' => 'Сальдо',
        'net_worth' => 'Чисті активи',
        'fallback' => 'Сума',
    ],

    'group_by' => 'Групувати за',

    'dimension' => [
        'category' => 'Категорія',
        'time_bucket' => 'Часовий інтервал',
        'counterparty' => 'Контрагент',
        'account' => 'Рахунок',
    ],

    'period' => [
        'heading' => 'Період',
        'this_month' => 'Цей місяць',
        'last_3_months' => 'Останні 3 місяці',
        'last_6_months' => 'Останні 6 місяців',
        'last_12_months' => 'Останні 12 місяців',
        'ytd' => 'З початку року',
        'this_year' => 'Цей рік',
        'custom' => 'Власний діапазон',
        'from' => 'Від',
        'to' => 'До',
        'error' => [
            'incomplete' => 'Вибери початкову та кінцеву дату.',
            'malformed' => 'Використай коректну дату у форматі РРРР-ММ-ДД.',
            'inverted' => 'Кінцева дата раніша за початкову.',
        ],
    ],

    'currency' => [
        'heading' => 'Валюта',
        'aria' => 'Режим валюти',
        'base' => 'Базова',
        'original' => 'Оригінальна',
    ],

    'granularity' => [
        'heading' => 'Деталізація',
        'aria' => 'Деталізація за часом',
        'monthly' => 'Помісячно',
        'weekly' => 'Потижнево',
    ],

    'filters' => [
        'heading' => 'Фільтри',
        'net_worth_note' => 'Чиста вартість — це залишок: діє лише фільтр рахунку.',
    ],

    'compare' => 'Порівняти з попереднім періодом',

    'viz' => [
        'heading' => 'Візуалізація',
        'table' => 'Таблиця',
        'bar' => 'Стовпчики',
        'line' => 'Лінія',
        'donut' => 'Кільце',
    ],

    'actions' => [
        'update_report' => 'Оновити звіт',
        'save_report' => 'Зберегти звіт',
        'report_name' => 'Назва звіту',
        'update' => 'Оновити',
        'save' => 'Зберегти',
        'cancel' => 'Скасувати',
        'export_csv' => 'Експорт CSV',
    ],

    'updating' => '… Оновлення',

    'empty' => [
        'heading' => 'Для цього вибору немає що показати',
        'body' => 'Спробуй розширити діапазон дат або прибрати фільтр.',
    ],

    'total_prefix' => 'Разом',
    'total' => 'Разом',
    'vs_previous' => 'проти попереднього періоду',
    'view_transactions' => 'Переглянути транзакції',

    'fx_excluded' => ':count рахунок не конвертовано — курс недоступний|:count рахунки не конвертовано — курс недоступний|:count рахунків не конвертовано — курс недоступний',

    'group_header' => [
        'category' => 'Категорія',
        'counterparty' => 'Контрагент',
        'account' => 'Рахунок',
        'month' => 'Місяць',
        'default' => 'Група',
    ],

    'chart' => [
        'other_currencies' => 'Графік у валюті :currency — :list не показано',
        'undrawn' => 'Немає в кільці — :amount рухається у зворотному напрямку',
        'bar_title' => 'Клацни стовпчик, щоб переглянути його транзакції',
        'line_title' => 'Клацни точку, щоб переглянути її транзакції',
        'donut_title' => 'Клацни сегмент, щоб переглянути його транзакції',
    ],

    'flash' => [
        'saved' => 'Звіт збережено.',
        'updated' => 'Звіт оновлено.',
    ],

    'filter' => [
        'account' => 'Рахунок',
        'account_count' => ':count рахунок|:count рахунки|:count рахунків',
        'remove_account' => 'Прибрати фільтр за рахунком',
        'account_dialog' => 'Фільтр за рахунком',

        'category' => 'Категорія',
        'category_count' => ':count категорія|:count категорії|:count категорій',
        'remove_category' => 'Прибрати фільтр за категорією',
        'category_dialog' => 'Фільтр за категорією',

        'counterparty' => 'Контрагент',
        'counterparty_count' => ':count контрагент|:count контрагенти|:count контрагентів',
        'remove_counterparty' => 'Прибрати фільтр за контрагентом',
        'counterparty_dialog' => 'Фільтр за контрагентом',

        'amount' => 'Сума',
        'remove_amount' => 'Прибрати фільтр за сумою',
        'amount_dialog' => 'Фільтр за сумою',
        'dir_both' => 'Обидва',
        'dir_in' => 'Вхід',
        'dir_out' => 'Вихід',
        'min' => 'Мін',
        'max' => 'Макс',
        'min_aria' => 'Мінімальна сума',
        'max_aria' => 'Максимальна сума',
    ],

    'other_movement' => 'Комісії та коригування (не враховано вище)',
    'other_movement_with_refunds' => 'Комісії, повернення та коригування (не враховано вище)',
];
