<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Вид контрагент: :type',
        'merchant' => 'Търговец',
        'personal' => 'Личен',
        'bank' => 'Банка',
        'government' => 'Държавна институция',
        'self' => 'Собствена сметка',
        'unknown' => 'Неизвестен',
    ],

    'filter_chips' => [
        'aria' => 'Филтрирай по вид',
        'all' => 'Всички',
        'merchant' => 'Търговци',
        'personal' => 'Лични',
        'bank' => 'Банки',
        'government' => 'Държавни институции',
        'self' => 'Собствени сметки',
        'unknown' => 'Неизвестни',
    ],

    'default_name' => [
        'bank_fee' => 'Банкова такса',
        'account_maintenance' => 'Такса за обслужване',
        'monthly_fee' => 'Месечна такса',
        'quarterly_fee' => 'Тримесечна такса',
        'annual_fee' => 'Годишна такса',
        'card_fee' => 'Такса за карта',
        'transaction_fee' => 'Такса за транзакция',
        'transfer_fee' => 'Такса за превод',
        'withdrawal_fee' => 'Такса за теглене',
        'transaction_levy' => 'Данък върху транзакциите',
        'foreign_transaction_fee' => 'Такса за валутна обмяна',
        'commission' => 'Комисиона',
        'debit_interest' => 'Дебитна лихва',
        'overdraft' => 'Такса за овърдрафт',
        'overdraft_interest' => 'Лихва по овърдрафт',
        'insufficient_funds' => 'Такса за недостатъчна наличност',
        'penalty_fee' => 'Наказателна такса',
        'loan_arrangement_fee' => 'Такса за отпускане на кредит',
    ],

    'cp_card' => [
        'aria' => 'Контрагент: :name',
        'recent_aria' => 'Скорошна активност',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Верига на финансиране: ',
        'join' => ' към ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN е скрит — кликни върху Покажи IBAN, за да го видиш',
        // i18n-review: bg · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN е скрит — докосни върху Покажи IBAN, за да го видиш',
        'show' => 'Покажи IBAN',
        'hide' => 'Скрий IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Съобщение за поверителност при личен контакт',
        'body' => '🔒 Това е личен контакт. IBAN и личните данни са скрити по подразбиране и никога не се включват в експорти.',
    ],

    'self_stub' => [
        'aria' => 'Не е истински контрагент',
        'heading' => 'Това всъщност не е контрагент',

        'body_rest_html' => ' се появява тук, защото фигурира в транзакциите ти като финансиращият участък между сметките. Но това е <strong>твоята собствена сметка</strong>, а не някой, с когото извършваш транзакции.',
        'body2' => 'Отвори изгледа на сметката за салдо, извлечения и пълна история на транзакциите.',
        'open_cta' => 'Отвори изгледа на сметката :name →',
        'hide_cta' => 'Скрий от този списък',
        'recent_legs' => 'Скорошни участъци между сметки',
    ],
];
