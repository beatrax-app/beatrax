<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Твій рахунок PayPal',
    'h1' => 'Підключи свій рахунок PayPal',

    'lede_html' => 'Перетягни експорт деталей транзакцій PayPal — у нідерландському рахунку PayPal він називається <em lang="nl">Rapport Transactiegegevens</em>. Звіт про баланс (<span lang="nl">Saldorapport</span>) не підійде — потрібні дані за кожною подією.',

    'format_group_aria' => 'PayPal експортує лише в CSV',
    'got_it_as' => 'Отримано як:',
    'badge_only_format' => 'єдиний формат',

    'mini' => [
        'login_label' => 'Увійти',
        'custom_label' => 'Власні виписки',
        'range_label' => 'Вибери період',
        'range_sub' => 'Останні 12 місяців',
        'download_label' => 'Завантажити як CSV',
    ],

    'drop_lead' => 'Перетягни сюди CSV із деталями транзакцій',
    'browse_file' => 'або вибери файл',

    'file_ready' => '· ✓ готово',

    'skip' => 'Пропустити цей крок',
    'continue' => 'Далі →',

    'errors' => [
        'required' => 'Спершу перетягни у це поле CSV-файл PayPal Rapport Transactiegegevens.',
        'max' => 'Цей файл завеликий. Експорти PayPal Rapport Transactiegegevens зазвичай значно менші за 10 MB.',
        'extensions' => 'Цей файл не схожий на CSV із PayPal. Завантаж із PayPal звіт Rapport Transactiegegevens (не звіт про баланс Saldorapport) у форматі CSV.',
        'unreadable' => 'Не вдалося прочитати цей файл. Повний текст помилки — у /dev/logs.',
    ],
];
