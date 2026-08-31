<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'сума',
            'currency' => 'валута',
            'description' => 'описание',
            'counterparty_name' => 'име на търговеца',
            'default' => 'стойност',
        ],
        'heading_cleaner' => 'Разписка от имейл съдържа по-ясна стойност за :field',
        'heading_different' => 'Разписка от имейл записва различна стойност за :field',
        'title' => 'Разписката и извлечението се разминават.',
        'body' => ':heading („:receipt“) спрямо извлечението („:statement“). Да предпочита ли Beatrax разписките при бъдещи разминавания?',
        'use_receipt' => 'Използвай разписката',
        'keep_statement' => 'Запази извлечението',
    ],
];
