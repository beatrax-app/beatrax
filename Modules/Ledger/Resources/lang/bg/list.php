<?php

declare(strict_types=1);

return [
    'page_title' => 'Транзакции',
    'heading' => 'Транзакции',

    'subtitle_searching' => 'Търсене в цялата история',
    'subtitle_full' => 'Пълна история.',
    'subtitle_recent' => 'Скорошни транзакции (последните 90 дни).',

    'currency_aria' => 'Изглед по валута',
    'currency_eur' => 'Само :code',
    'currency_original' => 'Оригинална валута',

    'show_recent' => 'Покажи само скорошните',
    'show_full' => 'Покажи цялата история',

    'empty_period' => 'Няма нищо за този период.',


    'empty_recent_has_older' => 'Нищо през последните 90 дни. По-старите ви трансакции са тук.',

    'empty_history' => 'Още няма трансакции.',
    'loading_more' => 'Зареждане на още транзакции',
    'load_more' => 'Зареди още',

    'split_badge' => 'Разделена · :count',
    'split_expand_aria' => 'Разделена между :count категория — разгъни, за да видиш|Разделена между :count категории — разгъни, за да видиш',

    'chain_badge' => 'верига',
    'chain_title' => 'Част от верига — отвори този ред, за да видиш',

    'table' => [
        'date' => 'Дата',
        'counterparty' => 'Контрагент',
        'category' => 'Категория',
        'tax' => 'Данък',
        'status' => 'Статус',
        'amount' => 'Сума',
    ],

    'search' => [
        'placeholder' => 'Търси търговец, описание, бележки…',
        'placeholder_short' => 'Търси транзакции…',
        'aria' => 'Търси транзакции',
        'clear_all' => 'Изчисти всичко',
        'filters' => 'Филтри',
        'open_filters_aria' => 'Отвори филтрите',
        'apply' => 'Приложи',
        'clear' => 'Изчисти',

        'count' => ':count транзакция|:count транзакции',
        'matching_suffix' => 'отговарящи на филтрите',
        'flow' => ':out изходящи / :in входящи',
    ],

    'no_results' => [
        'heading' => 'Няма съвпадения',
        'remove_prompt' => 'Опитай да премахнеш филтър, който може да стеснява резултатите:',
        'no_match_query' => 'Няма транзакции в цялата история, които да отговарят на „:query“.',
        'no_match_filters' => 'Няма транзакции, отговарящи на приложените филтри.',
        'did_you_mean' => 'Може би имаше предвид:',
        'account_fallback' => 'Сметка :id',
        'category_fallback' => 'Категория :id',
    ],

    'filter' => [
        'date' => 'Дата',
        'account' => 'Сметка',
        'amount' => 'Сума',
        'category' => 'Категория',
        'date_range' => 'Период',
        'from' => 'От',
        'to' => 'До',
        'custom_range' => 'Персонализиран период ×',
        'after' => 'След :date ×',
        'before' => 'Преди :date ×',
        'dir_both' => 'И двете',
        'dir_in' => 'Входящи',
        'dir_out' => 'Изходящи',
        'min' => 'Мин',
        'max' => 'Макс',
        'min_aria' => 'Минимална сума',
        'max_aria' => 'Максимална сума',
        'after_aria' => 'След дата',
        'before_aria' => 'Преди дата',
        'acct' => ':count сметка|:count сметки',
        'cat' => ':count категория|:count категории',
        'date_dialog' => 'Филтър по дата',
        'account_dialog' => 'Филтър по сметка',
        'amount_dialog' => 'Филтър по сума',
        'category_dialog' => 'Филтър по категория',
        'remove_date_aria' => 'Премахни филтъра по дата',
        'remove_account_aria' => 'Премахни филтъра по сметка',
        'remove_amount_aria' => 'Премахни филтъра по сума',
        'remove_category_aria' => 'Премахни филтъра по категория',

        'remove_named_aria' => 'Премахни филтъра :name',
    ],

    'date_preset' => [
        'this_month' => 'Този месец',
        'last_month' => 'Миналият месец',
        'this_year' => 'Тази година',
        'last_year' => 'Миналата година',
    ],
];
