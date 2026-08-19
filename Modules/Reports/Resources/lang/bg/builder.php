<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Некатегоризирани',
    'title' => 'Отчети',
    'page_title' => 'Отчети · Beatrax',
    'subtitle' => 'Състави отчет от регистъра си.',
    'controls_aria' => 'Настройки на отчета',
    'result_aria' => 'Резултат от отчета',
    'dismiss' => 'Отхвърли',

    'metric' => [
        'heading' => 'Показател',
        'spend' => 'Разходи',
        'income' => 'Приходи',
        'net' => 'Нето',
        'net_worth' => 'Нетна стойност',
        'fallback' => 'Сума',
    ],

    'group_by' => 'Групирай по',

    'dimension' => [
        'category' => 'Категория',
        'time_bucket' => 'Времеви интервал',
        'counterparty' => 'Контрагент',
        'account' => 'Сметка',
    ],

    'period' => [
        'heading' => 'Период',
        'this_month' => 'Този месец',
        'last_3_months' => 'Последните 3 месеца',
        'last_6_months' => 'Последните 6 месеца',
        'last_12_months' => 'Последните 12 месеца',
        'ytd' => 'От началото на годината',
        'this_year' => 'Тази година',
        'custom' => 'Собствен интервал',
        'from' => 'От',
        'to' => 'До',
    ],

    'currency' => [
        'heading' => 'Валута',
        'aria' => 'Режим на валутата',
        'base' => 'Базова',
        'original' => 'Оригинална',
    ],

    'granularity' => [
        'heading' => 'Детайлност',
        'aria' => 'Времева детайлност',
        'monthly' => 'Месечно',
        'weekly' => 'Седмично',
    ],

    'filters' => [
        'heading' => 'Филтри',
    ],

    'compare' => 'Сравни с предишния период',

    'viz' => [
        'heading' => 'Визуализация',
        'table' => 'Таблица',
        'bar' => 'Стълбове',
        'line' => 'Линия',
        'donut' => 'Пръстен',
    ],

    'actions' => [
        'update_report' => 'Обнови отчета',
        'save_report' => 'Запази отчета',
        'report_name' => 'Име на отчета',
        'update' => 'Обнови',
        'save' => 'Запази',
        'cancel' => 'Отказ',
        'export_csv' => 'Експортирай CSV',
    ],

    'updating' => '… Обновяване',

    'empty' => [
        'heading' => 'Няма какво да се покаже за този избор',
        'body' => 'Опитай да разшириш периода или да премахнеш филтър.',
    ],

    'total_prefix' => 'Общо',
    'total' => 'Общо',
    'vs_previous' => 'спрямо предишния период',
    'view_transactions' => 'Виж транзакциите',

    'fx_excluded' => '{0} няма неконвертирани сметки — няма наличен курс|[1,1] :count сметка не е конвертирана — няма наличен курс|[2,*] :count сметки не са конвертирани — няма наличен курс',

    'group_header' => [
        'category' => 'Категория',
        'counterparty' => 'Контрагент',
        'account' => 'Сметка',
        'month' => 'Месец',
        'default' => 'Група',
    ],

    'chart' => [
        'bar_title' => 'Кликни върху стълб, за да видиш транзакциите му',
        'line_title' => 'Кликни върху точка, за да видиш транзакциите ѝ',
        'donut_title' => 'Кликни върху сегмент, за да видиш транзакциите му',
    ],

    'flash' => [
        'saved' => 'Отчетът е запазен.',
        'updated' => 'Отчетът е обновен.',
    ],

    'filter' => [
        'account' => 'Сметка',
        'account_count' => ':count сметка|:count сметки',
        'remove_account' => 'Премахни филтъра по сметка',
        'account_dialog' => 'Филтър по сметка',

        'category' => 'Категория',
        'category_count' => ':count категория|:count категории',
        'remove_category' => 'Премахни филтъра по категория',
        'category_dialog' => 'Филтър по категория',

        'counterparty' => 'Контрагент',
        'counterparty_count' => ':count контрагент|:count контрагента',
        'remove_counterparty' => 'Премахни филтъра по контрагент',
        'counterparty_dialog' => 'Филтър по контрагент',

        'amount' => 'Сума',
        'remove_amount' => 'Премахни филтъра по сума',
        'amount_dialog' => 'Филтър по сума',
        'dir_both' => 'И двете',
        'dir_in' => 'Входящи',
        'dir_out' => 'Изходящи',
        'min' => 'Мин.',
        'max' => 'Макс.',
        'min_aria' => 'Минимална сума',
        'max_aria' => 'Максимална сума',
    ],
];
