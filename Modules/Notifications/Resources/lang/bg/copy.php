<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Импортът приключи',
        'receipts' => 'Намерени са нови разписки',
        'drift' => 'Повтарящо се плащане се промени',
        'forecast' => 'Предстои недостиг на средства',
        'budget_nudge' => 'Бюджетът е почти изчерпан',
        'savings_prompt' => 'Съществува по-евтин план',
        'ics_statement_ready' => 'Готово е ново извлечение от ICS',
        'payment_reminder_confident' => 'Плащане с падеж :day',
        'payment_reminder_hedged' => 'Плащане с падеж около :day',
        'position_digest_daily' => 'Твоята дневна равносметка',
        'position_digest_weekly' => 'Твоята седмична равносметка',
    ],

    'body' => [
        'budget_nudge' => ':category — изразходвани :spent от :budget.',
        'receipts_matched' => ':count разписка е съпоставена от имейла ти.|:count разписки са съпоставени от имейла ти.',
        'import_finished' => ':count транзакция е импортирана.|:count транзакции са импортирани.',
        'drift' => 'Повтарящо се плащане се промени :direction с :delta :currency.',
        'forecast' => 'Прогнозното ти салдо пада под нулата през следващите 30 дни.',
        'ics_statement_ready' => 'Изтегли го от портала на ICS и го пусни в Beatrax, за да са актуални разходите по тази карта.',
        'payment_reminder_hedged' => ':name — очаквано около :day, :amount.',
        'payment_reminder_confident' => ':name — с падеж :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/мес.)',
    ],

    'drift_direction' => [
        'up' => 'нагоре',
        'down' => 'надолу',
    ],

    'digest' => [
        'nothing_notable' => 'Нищо не изисква вниманието ти.',
        'flow' => 'Входящи :in, изходящи :out, нето :net.',
        'over_budget' => ':amount над бюджета досега.',
        'payments_due' => '1 плащане с падеж през този период.|:count плащания с падеж през този период.',
        'shortfall' => 'Предстои недостиг на средства.',
    ],
];
