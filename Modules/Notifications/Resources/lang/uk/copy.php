<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Імпорт завершено',
        'receipts' => 'Знайдено нові чеки',
        'manual_entry' => 'Касову книгу оновлено',
        'migration_finished' => 'Перенесення завершено',
        'drift' => 'Регулярне списання змінилося',
        'forecast' => 'Попереду нестача коштів',
        'budget_nudge' => 'Бюджет майже витрачено',
        'budget_nudge_spent' => 'Бюджет витрачено',
        'budget_nudge_over' => 'Бюджет перевищено',
        'savings_prompt' => 'Місце, де ти можеш заощадити',
        'ics_statement_ready' => 'Готова нова виписка ICS',
        'payment_reminder_confident' => 'Термін платежу: :day (:date)',
        'payment_reminder_hedged' => 'Термін платежу: близько :day (:date)',
        'position_digest_daily' => 'Твій щоденний стан',
        'position_digest_weekly' => 'Твій щотижневий стан',
    ],

    'body' => [
        'budget_nudge' => ':category — витрачено :spent із :budget.',
        'receipts_matched' => 'Зіставлено :count чек із твоєї пошти.|Зіставлено :count чеки із твоєї пошти.|Зіставлено :count чеків із твоєї пошти.',
        'import_finished' => 'Імпортовано :count транзакцію.|Імпортовано :count транзакції.|Імпортовано :count транзакцій.',
        'manual_entry' => 'Вручну додано :count запис.|Вручну додано :count записи.|Вручну додано :count записів.',
        'migration_finished' => 'Твій бюджет перенесено, зокрема :count транзакцію.|Твій бюджет перенесено, зокрема :count транзакції.|Твій бюджет перенесено, зокрема :count транзакцій.',
        'drift' => 'Регулярне списання пішло :direction на :amount.',
        'forecast' => 'Твій прогнозований баланс опуститься нижче нуля :date.',
        'forecast_buffer' => 'Твій прогнозований баланс опуститься нижче твого буфера (:buffer) :date.',
        'ics_statement_ready' => 'Завантаж її з порталу ICS і додай у Beatrax, щоб витрати за цією карткою залишалися актуальними.',
        'payment_reminder_hedged' => ':name — очікується близько :day (:date), :amount.',
        'payment_reminder_confident' => ':name — термін :day (:date), :amount.',
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
