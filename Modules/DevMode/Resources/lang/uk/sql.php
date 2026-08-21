<?php

declare(strict_types=1);

return [
    'tables' => 'Таблиці',
    'schema_viewer_aria' => 'Переглядач схеми',
    'columns' => 'стовпці',
    'indexes' => 'індекси',
    'foreign_keys' => 'зовнішні ключі',
    'browse' => 'Переглянути',
    'heading' => 'SQL',

    'subtitle_html' => 'Панель запитів лише для SELECT. Валідатор (на етапі розбору) і PRAGMA <code class="font-mono text-xs">query_only = 1</code> (на етапі рушія) відхиляють будь-що, крім SELECT. Жорстке обмеження 5 секунд за годинником.',
    'advanced_off_strong' => 'Режим «Додатково» ВИМКНЕНО.',
    'advanced_off_hint' => 'Увімкни «Додатково» (Режим розробника → Додатково), щоб виконувати запити.',
    'statement_label' => 'Вираз SELECT',
    'run' => 'Виконати',
    'rows_meta' => ':rows рядок · :durationмс|:rows рядки · :durationмс|:rows рядків · :durationмс',
    'no_rows' => 'Запит не повернув жодного рядка.',

    'errors' => [
        'advanced_off' => 'Увімкни «Додатково» (Режим розробника → Додатково), щоб виконувати запити.',
        'only_select' => 'Дозволені лише вирази SELECT. Причина відхилення: :reason.',
        'timeout' => 'Запит перевищив ліміт у 5 секунд. Уточни запит і спробуй ще раз.',
        'engine' => 'Помилка SQL: :message',
        'unknown_table' => 'Невідома таблиця.',
    ],
];
