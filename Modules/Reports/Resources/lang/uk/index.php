<?php

declare(strict_types=1);

return [
    'title' => 'Звіти',
    'page_title' => 'Звіти · Beatrax',
    'saved_report' => ':count збережений звіт|:count збережені звіти|:count збережених звітів',
    'pinned_count' => ':count з :max закріплений|:count з :max закріплені|:count з :max закріплених',
    'dismiss' => 'Відхилити',

    'build_new' => 'Створити новий звіт',
    'view_mode_aria' => 'Режим перегляду',
    'cards' => 'Картки',
    'list' => 'Список',

    'empty' => [
        'heading' => 'Збережених звітів ще немає',
        'body' => 'Створи звіт нижче та збережи його, щоб він з’явився тут.',
        'cta' => 'Створи свій перший звіт →',
    ],

    'pin' => [
        'pinned_aria' => 'Закріплено — відкріпити від панелі',
        'pin_aria' => 'Закріпити — закріпити на панелі',
        'pinned_title' => 'Закріплено',
        'pin_title' => 'Закріпити на панелі',
        'pinned_label' => 'Закріплено',
        'pin_label' => 'Закріпити',
    ],

    'open' => 'Відкрити',
    'edit' => 'Змінити',

    'delete_confirm' => 'Видалити «:name»?',
    'delete_report' => 'Видалити звіт',
    'cancel' => 'Скасувати',
    'delete' => 'Видалити',
    'delete_aria' => 'Видалити: :name',

    'col' => [
        'name' => 'Назва',
        'summary' => 'Підсумок',
        'pinned' => 'Закріплено',
        'actions' => 'Дії',
    ],

    'flash' => [
        'not_found' => 'Звіт не знайдено (можливо, його видалили в іншій вкладці).',
        'deleted' => 'Звіт видалено.',
    ],
    'pin_cap' => 'Можна закріпити :max звіт. Відкріпи його, щоб додати цей.|Можна закріпити щонайбільше :max звіти. Відкріпи один, щоб додати цей.|Можна закріпити щонайбільше :max звітів. Відкріпи один, щоб додати цей.',

    'summary' => [
        'metric' => [
            'spend' => 'Витрати',
            'income' => 'Надходження',
            'net' => 'Сальдо',
            'net_worth' => 'Чисті активи',
            'fallback' => 'Сума',
        ],
        'dimension' => [
            'category' => 'категорія',
            'time_bucket' => 'часовий проміжок',
            'counterparty' => 'контрагент',
            'account' => 'рахунок',
            'fallback' => 'категорія',
        ],
        'period' => [
            'this_month' => 'Цей місяць',
            'last_3_months' => 'Останні 3 місяці',
            'last_6_months' => 'Останні 6 місяців',
            'last_12_months' => 'Останні 12 місяців',
            'ytd' => 'Від початку року',
            'this_year' => 'Цей рік',
            'custom' => 'Власний діапазон',
        ],
        'with_dimension' => ':metric · розріз: :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
