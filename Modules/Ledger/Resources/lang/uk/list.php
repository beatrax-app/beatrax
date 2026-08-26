<?php

declare(strict_types=1);

return [
    'page_title' => 'Транзакції',
    'heading' => 'Транзакції',

    'subtitle_searching' => 'Пошук по всій історії',
    'subtitle_full' => 'Повна історія.',
    'subtitle_recent' => 'Останні транзакції (за 90 днів).',

    'currency_aria' => 'Вигляд валюти',
    'currency_eur' => 'Лише :code',
    'currency_original' => 'Оригінальна валюта',

    'show_recent' => 'Показати лише останні',
    'show_full' => 'Показати повну історію',

    'empty_period' => 'За цей період нічого немає.',

    'loading_more' => 'Завантаження наступних транзакцій',
    'load_more' => 'Завантажити ще',

    'split_badge' => 'Розподіл · :count',
    'split_expand_aria' => 'Розподілено між :count категорією — розгорни, щоб переглянути|Розподілено між :count категоріями — розгорни, щоб переглянути|Розподілено між :count категоріями — розгорни, щоб переглянути',

    'chain_badge' => 'ланцюг',
    'chain_title' => 'Частина ланцюга — відкрий цей рядок, щоб переглянути',

    'table' => [
        'date' => 'Дата',
        'counterparty' => 'Контрагент',
        'category' => 'Категорія',
        'tax' => 'Податок',
        'status' => 'Статус',
        'amount' => 'Сума',
    ],

    'search' => [
        'placeholder' => 'Пошук продавця, опису, нотаток…',
        'placeholder_short' => 'Пошук транзакцій…',
        'aria' => 'Пошук транзакцій',
        'clear_all' => 'Очистити все',
        'filters' => 'Фільтри',
        'open_filters_aria' => 'Відкрити фільтри',
        'apply' => 'Застосувати',
        'clear' => 'Очистити',

        'count' => ':count транзакція|:count транзакції|:count транзакцій',
        'matching_suffix' => 'за вибраними фільтрами',
        'flow' => ':out вих. / :in вх.',
    ],

    'no_results' => [
        'heading' => 'Немає збігів',
        'remove_prompt' => 'Спробуй прибрати фільтр, який може звужувати результати:',
        'no_match_query' => 'У всій історії немає транзакцій, що відповідають «:query».',
        'no_match_filters' => 'Жодна транзакція не відповідає застосованим фільтрам.',
        'did_you_mean' => 'Можливо, ти маєш на увазі:',
        'account_fallback' => 'Рахунок :id',
        'category_fallback' => 'Категорія :id',
    ],

    'filter' => [
        'date' => 'Дата',
        'account' => 'Рахунок',
        'amount' => 'Сума',
        'category' => 'Категорія',
        'date_range' => 'Діапазон дат',
        'from' => 'Від',
        'to' => 'До',
        'custom_range' => 'Власний діапазон ×',
        'after' => 'Після :date ×',
        'before' => 'До :date ×',
        'dir_both' => 'Обидва',
        'dir_in' => 'Вх.',
        'dir_out' => 'Вих.',
        'min' => 'Мін.',
        'max' => 'Макс.',
        'min_aria' => 'Мінімальна сума',
        'max_aria' => 'Максимальна сума',
        'after_aria' => 'Після дати',
        'before_aria' => 'До дати',
        'acct' => ':count рахунок|:count рахунки|:count рахунків',
        'cat' => ':count категорія|:count категорії|:count категорій',
        'date_dialog' => 'Фільтр дати',
        'account_dialog' => 'Фільтр рахунку',
        'amount_dialog' => 'Фільтр суми',
        'category_dialog' => 'Фільтр категорії',
        'remove_date_aria' => 'Прибрати фільтр дати',
        'remove_account_aria' => 'Прибрати фільтр рахунку',
        'remove_amount_aria' => 'Прибрати фільтр суми',
        'remove_category_aria' => 'Прибрати фільтр категорії',

        'remove_named_aria' => 'Прибрати фільтр: :name',
    ],

    'date_preset' => [
        'this_month' => 'Цей місяць',
        'last_month' => 'Минулий місяць',
        'this_year' => 'Цей рік',
        'last_year' => 'Минулий рік',
    ],
];
