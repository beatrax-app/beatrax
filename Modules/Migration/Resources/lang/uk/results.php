<?php

declare(strict_types=1);

return [
    'page_title' => 'Імпорт завершено',

    'heading_complete' => 'Імпорт завершено',
    'heading_update' => 'Оновлення застосовано',

    'summary_line' => 'Імпортовано: :categories, :budget_months і :transactions.',
    'summary_categories' => ':count категорія|:count категорії|:count категорій',
    'summary_budget_months' => ':count місяць бюджету|:count місяці бюджету|:count місяців бюджету',
    'summary_transactions' => ':count транзакція|:count транзакції|:count транзакцій',
    'summary_attention' => ':count позиція потребує уваги — дивись нижче.|:count позиції потребують уваги — дивись нижче.|:count позицій потребує уваги — дивись нижче.',

    'stats' => [
        'category' => 'Категорії',
        'account' => 'Рахунки',
        // i18n-review: uk · stats.payee — the count is payees the import
        // linked, not ones it created; check the participle agrees here.
        'payee' => 'Пов\'язані контрагенти',
        'transaction' => 'Транзакції',
        'budget' => 'Місяці бюджету',
    ],

    'groups' => [
        'extra' => 'Не імпортовано',
        'conflict' => 'Потребує твого рішення',
    ],

    'view_transactions' => 'Переглянути транзакції',
    'view_budgets' => 'Переглянути бюджети',
    'back' => 'Назад до міграцій',
];
