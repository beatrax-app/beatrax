<?php

declare(strict_types=1);

return [
    'page_title' => 'Импортът е завършен',

    'heading_complete' => 'Импортът е завършен',
    'heading_update' => 'Актуализацията е приложена',

    'summary_line' => 'Импортирани са :categories, :budget_months и :transactions.',
    'summary_categories' => ':count категория|:count категории',
    'summary_budget_months' => ':count бюджетен месец|:count бюджетни месеца',
    'summary_transactions' => ':count транзакция|:count транзакции',
    'summary_attention' => ':count елемент все още изисква внимание — виж по-долу.|:count елемента все още изискват внимание — виж по-долу.',

    'stats' => [
        'category' => 'Категории',
        'account' => 'Сметки',
        // i18n-review: bg · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Свързани контрагенти',
        'transaction' => 'Транзакции',
        'budget' => 'Бюджетни месеци',
    ],

    'groups' => [
        'extra' => 'Неимпортирани',
        'conflict' => 'Изисква твоето решение',
    ],

    'view_transactions' => 'Виж транзакциите',
    'view_budgets' => 'Виж бюджетите',
    'back' => 'Назад към миграциите',
];
