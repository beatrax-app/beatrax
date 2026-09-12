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
        'heading_restated' => 'По-късно извлечение записва различна стойност за :field',
        'restated_title' => 'Банката коригира тази транзакция.',
        'restated_body' => ':heading („:incoming“) спрямо вече записания ред („:stored“). Да предпочита ли Beatrax новата стойност при бъдещи разминавания?',
        'use_restated' => 'Използвай новата стойност',
        'keep_stored' => 'Запази записаната стойност',
    ],
];
