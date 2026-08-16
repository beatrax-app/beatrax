<?php

declare(strict_types=1);

return [
    'page_title' => 'Импортиране от YNAB / Actual',

    'eyebrow' => 'Миграции',
    'heading' => 'Импортиране от YNAB / Actual',
    'intro' => 'Прехвърли дървото си от категории, историята на бюджетите и транзакциите от YNAB4, новия YNAB или Actual Budget. Нищо не се записва в регистъра ти, докато не прегледаш и потвърдиш.',
    'reconcile_context' => 'Проверка за актуализации спрямо последния ти импорт от :product.',

    'source_label' => 'Източник',
    'file_label' => 'Файл',
    'parse_button' => 'Анализирай експорта',

    'hints' => [
        'ynab4' => 'Експортирай целия си бюджет като ZIP файл от менюто File → Export на YNAB4.',
        'nynab' => 'Експортирай бюджета си от nYNAB чрез File → Export Budget, след което архивирай в ZIP експортираните CSV файлове.',
        'actual' => 'Експортирай бюджета си като ZIP файл от Settings → Export data на Actual Budget.',
    ],

    'errors' => [
        'unrecognised' => 'Това не изглежда като експорт от YNAB4, nYNAB или Actual, който можем да разчетем. Провери файла и опитай отново.',
        'file_too_large' => 'Този файл е твърде голям за експорт за миграция.',
    ],
];
