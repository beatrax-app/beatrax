<?php

declare(strict_types=1);

return [
    'title' => 'Отчети',
    'page_title' => 'Отчети · Beatrax',
    'saved_report' => '{0} :count запазени отчета|[1,1] :count запазен отчет|[2,*] :count запазени отчета',
    'pinned_count' => ':count от :max закачен|:count от :max закачени',
    'dismiss' => 'Отхвърли',

    'build_new' => 'Създай нов отчет',
    'view_mode_aria' => 'Изглед',
    'cards' => 'Карти',
    'list' => 'Списък',

    'empty' => [
        'heading' => 'Още няма запазени отчети',
        'body' => 'Създай един по-долу и го запази, за да се появи тук.',
        'cta' => 'Създай първия си отчет →',
    ],

    'pin' => [
        'pinned_aria' => 'Закачен — откачи от таблото',
        'pin_aria' => 'Закачи — закачи на таблото',
        'pinned_title' => 'Закачен',
        'pin_title' => 'Закачи на таблото',
        'pinned_label' => 'Закачен',
        'pin_label' => 'Закачи',
    ],

    'open' => 'Отвори',
    'edit' => 'Редактирай',

    'delete_confirm' => 'Да изтрия ли „:name“?',
    'delete_report' => 'Изтрий отчета',
    'cancel' => 'Отказ',
    'delete' => 'Изтрий',
    'delete_aria' => 'Изтрий :name',

    'col' => [
        'name' => 'Име',
        'summary' => 'Обобщение',
        'pinned' => 'Закачен',
        'actions' => 'Действия',
    ],

    'flash' => [
        'not_found' => 'Отчетът не е намерен (възможно е да е изтрит в друг раздел).',
        'deleted' => 'Отчетът е изтрит.',
    ],
    'pin_cap' => 'Можеш да закачиш най-много :max отчета. Откачи един, за да добавиш този.',

    'summary' => [
        'metric' => [
            'spend' => 'Разходи',
            'income' => 'Приходи',
            'net' => 'Нето',
            'net_worth' => 'Нетна стойност',
            'fallback' => 'Сума',
        ],
        'dimension' => [
            'category' => 'категория',
            'time_bucket' => 'времеви интервал',
            'counterparty' => 'контрагент',
            'account' => 'сметка',
            'fallback' => 'категория',
        ],
        'period' => [
            'this_month' => 'Този месец',
            'last_3_months' => 'Последните 3 месеца',
            'last_6_months' => 'Последните 6 месеца',
            'last_12_months' => 'Последните 12 месеца',
            'ytd' => 'От началото на годината',
            'this_year' => 'Тази година',
            'custom' => 'Собствен интервал',
        ],
        'with_dimension' => ':metric · по :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
