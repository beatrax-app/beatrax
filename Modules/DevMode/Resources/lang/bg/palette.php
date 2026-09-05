<?php

declare(strict_types=1);

return [
    'search_placeholder' => 'Пиши, за да търсиш изгледи, команди и действия. Натисни Esc, за да затвориш.',
    'search_aria' => 'Пиши, за да търсиш изгледи, команди и действия',
    'dialog_aria' => 'Палитра с команди',
    'token_suggest_aria' => 'Предложения за токени',
    'rail_view' => 'Изглед',
    'rail_dev' => 'Dev',
    'rail_action' => 'Действие',
    'rail_recent' => 'Скорошни',
    'no_recent' => 'Още няма скорошни избори.',
    'section_transactions' => 'Транзакции',
    'section_counterparties' => 'Контрагенти',
    'section_categories' => 'Категории',
    'section_goals_recurring' => 'Цели и повтарящи се',
    'no_name' => '(без име)',
    'see_all' => 'Виж :count резултат →|Виж всички :count резултата →',
    'no_transactions' => 'Няма транзакции за „:query“',
    'source_txn' => 'txn',
    'source_counterparty' => 'контрагент',
    'source_category' => 'категория',
    'results_aria' => 'Резултати',
    'no_results' => 'Няма резултати.',
    'foot_navigate' => 'навигация',
    'foot_select' => 'избор',
    'foot_close' => 'затвори',
    'close_aria' => 'Затвори търсенето',
    'close_caption' => 'Затвори',
    'foot_try' => 'Опитай',
    'results' => ':count резултат|:count резултата',

    'action' => [
        'run_import' => ['label' => 'Стартирай импорт', 'hint' => 'Отвори асистента за импорт'],
        'scan_email' => ['label' => 'Отвори пощенските кутии', 'hint' => 'Свързаните ти пощенски кутии'],
        'open_profile' => ['label' => 'Отвори профила', 'hint' => 'Настройки — профил и предпочитания'],
        'toggle_theme' => ['label' => 'Отвори настройките за изглед', 'hint' => 'Светла, тъмна или системна'],
    ],

    'run_command' => 'Изпълни :command',

    'nav' => [
        'overview' => ['label' => 'Преглед за разработчици', 'hint' => 'Системни плочки + скорошни изпълнения'],
        'artisan' => ['label' => 'Изпълнение на Artisan', 'hint' => 'Изпълнявай разрешените команди'],
        'audit' => ['label' => 'Одитен дневник за разработка', 'hint' => 'Твоите действия в режима за разработка'],
        'logs' => ['label' => 'Проследяване на дневниците', 'hint' => 'Поток на живо от laravel-*.log'],
        'queue' => ['label' => 'Инспектор на опашката', 'hint' => 'Изчакващи / неуспешни / партиди'],
        'doctor' => ['label' => 'Диагностика', 'hint' => 'Системни проби'],
        'sql' => ['label' => 'SQL панел', 'hint' => 'Браузър само за SELECT'],
        'system' => ['label' => 'Моментна снимка на системата', 'hint' => 'Обкръжение + пътища + конфигурация'],
        'horizon' => ['label' => 'Horizon', 'hint' => 'Вградено табло за опашката'],
        'sync_health' => ['label' => 'Състояние на синхронизацията', 'hint' => 'Операции по сливане под карантина или пропуснати'],
    ],
];
