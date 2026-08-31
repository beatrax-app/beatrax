<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Импортът приключи',
        'receipts' => 'Намерени са нови разписки',
        'manual_entry' => 'Касовата книга е обновена',
        'migration_finished' => 'Мигрирането приключи',
        'drift' => 'Повтарящо се плащане се промени',
        'forecast' => 'Предстои недостиг на средства',
        'budget_nudge' => 'Бюджетът е почти изчерпан',
        'budget_nudge_spent' => 'Бюджетът е изчерпан',
        'budget_nudge_over' => 'Бюджетът е надхвърлен',
        'savings_prompt' => 'Място, където можеш да спестиш',
        'ics_statement_ready' => 'Готово е ново извлечение от ICS',
        'payment_reminder_confident' => 'Плащане с падеж :day (:date)',
        'payment_reminder_hedged' => 'Плащане с падеж около :day (:date)',
        'position_digest_daily' => 'Твоята дневна равносметка',
        'position_digest_weekly' => 'Твоята седмична равносметка',
    ],

    'body' => [
        'budget_nudge' => ':category — изразходвани :spent от :budget.',
        'receipts_matched' => ':count разписка е съпоставена от имейла ти.|:count разписки са съпоставени от имейла ти.',
        'import_finished' => ':count транзакция е импортирана.|:count транзакции са импортирани.',
        'manual_entry' => 'Добавен е :count ръчен запис.|Добавени са :count ръчни записа.',
        'migration_finished' => 'Бюджетът ти е прехвърлен, включително :count транзакция.|Бюджетът ти е прехвърлен, включително :count транзакции.',
        'drift' => 'Повтарящо се плащане се промени :direction с :amount.',
        'forecast' => 'Прогнозното ти салдо пада под нулата на :date.',
        'forecast_buffer' => 'Прогнозното ти салдо пада под буфера ти от :buffer на :date.',
        'ics_statement_ready' => 'Изтегли го от портала на ICS и го пусни в Beatrax, за да са актуални разходите по тази карта.',
        'payment_reminder_hedged' => ':name — очаквано около :day (:date), :amount.',
        'payment_reminder_confident' => ':name — с падеж :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'нагоре',
        'down' => 'надолу',
    ],

    'digest' => [
        'nothing_notable' => 'Нищо не изисква вниманието ти.',
        'flow' => 'Входящи :in, изходящи :out, нето :net.',
        'over_budget' => ':amount над бюджета досега.',
        'payments_due' => ':count плащане с падеж през този период.|:count плащания с падеж през този период.',
        'shortfall' => 'Предстои недостиг на средства.',
    ],
];
