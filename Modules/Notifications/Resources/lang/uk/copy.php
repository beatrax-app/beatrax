<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Імпорт завершено',
        'receipts' => 'Знайдено нові чеки',
        'drift' => 'Регулярне списання змінилося',
        'forecast' => 'Попереду нестача коштів',
        'budget_nudge' => 'Бюджет майже витрачено',
        'savings_prompt' => 'Є дешевший тариф',
        'ics_statement_ready' => 'Готова нова виписка ICS',
        'payment_reminder_confident' => 'Термін платежу: :day',
        'payment_reminder_hedged' => 'Термін платежу: близько :day',
        'position_digest_daily' => 'Твій щоденний стан',
        'position_digest_weekly' => 'Твій щотижневий стан',
    ],

    'body' => [
        'budget_nudge' => ':category — витрачено :spent із :budget.',
        'receipts_matched' => 'Зіставлено :count чек із твоєї пошти.|Зіставлено :count чеки із твоєї пошти.|Зіставлено :count чеків із твоєї пошти.',
        'import_finished' => 'Імпортовано :count транзакцію.|Імпортовано :count транзакції.|Імпортовано :count транзакцій.',
        'drift' => 'Регулярне списання пішло :direction на :amount.',
        'forecast' => 'Твій прогнозований баланс опуститься нижче нуля протягом наступних 30 днів.',
        'ics_statement_ready' => 'Завантаж її з порталу ICS і додай у Beatrax, щоб витрати за цією карткою залишалися актуальними.',
        'payment_reminder_hedged' => ':name — очікується близько :day, :amount.',
        'payment_reminder_confident' => ':name — термін :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/міс.)',
    ],

    'drift_direction' => [
        'up' => 'угору',
        'down' => 'униз',
    ],

    'digest' => [
        'nothing_notable' => 'Нічого не потребує твоєї уваги.',
        'flow' => 'Надходження :in, витрати :out, сальдо :net.',
        'over_budget' => 'Понад бюджет наразі: :amount.',
        'payments_due' => 'У цьому періоді :count платіж до сплати.|У цьому періоді :count платежі до сплати.|У цьому періоді :count платежів до сплати.',
        'shortfall' => 'Попереду нестача коштів.',
    ],
];
