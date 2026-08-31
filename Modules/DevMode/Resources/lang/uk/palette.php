<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Введи, щоб шукати вигляди, команди та дії. Натисни Esc, щоб закрити.',
    'search_aria' => 'Введи, щоб шукати вигляди, команди та дії',
    'dialog_aria' => 'Палітра команд',
    'token_suggest_aria' => 'Пропозиції токенів',
    'rail_view' => 'Вигляд',
    'rail_dev' => 'Dev',
    'rail_action' => 'Дія',
    'rail_recent' => 'Нещодавні',
    'no_recent' => 'Нещодавніх виборів ще немає.',
    'section_transactions' => 'Транзакції',
    'section_counterparties' => 'Контрагенти',
    'section_categories' => 'Категорії',
    'section_goals_recurring' => 'Цілі та регулярні платежі',
    'no_name' => '(без назви)',
    // i18n-review: uk · see_all — всі before a genitive plural is the contested
    // half; the previous line avoided it by moving the count into brackets. The
    // first two arms take the accusative and are not in question.
    'see_all' => 'Переглянути :count результат →|Переглянути :count результати →|Переглянути всі :count результатів →',
    'no_transactions' => 'Немає транзакцій за запитом ":query"',
    'source_txn' => 'txn',
    'source_counterparty' => 'контрагент',
    'source_category' => 'категорія',
    'results_aria' => 'Результати',
    'no_results' => 'Немає результатів.',
    'foot_navigate' => 'навігація',
    'foot_select' => 'вибір',
    'foot_close' => 'закрити',
    'close_aria' => 'Закрити пошук',
    'close_caption' => 'Закрити',
    'foot_try' => 'Спробуй',
    'results' => ':count результат|:count результати|:count результатів',

    'action' => [
        'run_import' => ['label' => 'Запустити імпорт', 'hint' => 'Відкрити майстер імпорту'],
        'scan_email' => ['label' => 'Сканувати пошту зараз', 'hint' => 'Негайно запустити синхронізацію поштової скриньки'],
        'open_profile' => ['label' => 'Відкрити профіль', 'hint' => 'Налаштування — обліковий запис і параметри'],
        'toggle_theme' => ['label' => 'Змінити тему', 'hint' => 'Перемикання між світлою та темною темою'],
    ],

    'run_command' => 'Виконати :command',

    'nav' => [
        'overview' => ['label' => 'Dev-огляд', 'hint' => 'Системні плитки + останні запуски'],
        'artisan' => ['label' => 'Artisan runner', 'hint' => 'Запуск команд зі списку дозволених'],
        'audit' => ['label' => 'Dev-журнал аудиту', 'hint' => 'Кожна дія в режимі розробника'],
        // i18n-review: uk · nav.logs — uk has no settled noun for a log tailer, so the
        // label names the act of watching the file and the hint carries «живий» from
        // logs.php, so the pair reads as one feature.
        'logs' => ['label' => 'Перегляд журналів', 'hint' => 'Живий потік laravel-*.log'],
        'queue' => ['label' => 'Інспектор черги', 'hint' => 'В очікуванні / невдалі / пакети'],
        'doctor' => ['label' => 'Doctor', 'hint' => 'Системні проби'],
        'sql' => ['label' => 'Панель SQL', 'hint' => 'Переглядач лише для SELECT'],
        'system' => ['label' => 'Знімок системи', 'hint' => 'Середовище + шляхи + конфігурація'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Вбудована панель черги'],
        // i18n-review: uk · nav.sync_health.hint — «об’єднання» is the merge word the
        // Import files already use; whether it also reads for a sync merge op, rather
        // than «злиття», is the open half.
        'sync_health' => ['label' => 'Стан синхронізації', 'hint' => 'У карантині / пропущені операції об’єднання'],
    ],
];
