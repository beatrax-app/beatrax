<?php

declare(strict_types=1);

return [
    'type_chip' => [
        'aria' => 'Тип контрагента: :type',
        'merchant' => 'Продавець',
        'personal' => 'Особистий',
        'bank' => 'Банк',
        'government' => 'Держава',
        'self' => 'Власний',
        'unknown' => 'Невідомо',
    ],

    'filter_chips' => [
        'aria' => 'Фільтрувати за типом',
        'all' => 'Усі',
        'merchant' => 'Продавці',
        'personal' => 'Особисті',
        'bank' => 'Банки',
        'government' => 'Державні',
        'self' => 'Власні',
        'unknown' => 'Невідомі',
    ],

    'default_name' => [
        'bank_fee' => 'Банківська комісія',
        'account_maintenance' => 'Плата за обслуговування',
        'monthly_fee' => 'Щомісячна плата',
        'quarterly_fee' => 'Щоквартальна плата',
        'annual_fee' => 'Річна плата',
        'card_fee' => 'Плата за картку',
        'transaction_fee' => 'Комісія за транзакцію',
        'transfer_fee' => 'Комісія за переказ',
        'withdrawal_fee' => 'Комісія за зняття',
        'transaction_levy' => 'Податок на транзакції',
        'foreign_transaction_fee' => 'Комісія за конвертацію валюти',
        'commission' => 'Комісія',
        'debit_interest' => 'Дебетові відсотки',
        'overdraft' => 'Комісія за овердрафт',
        'overdraft_interest' => 'Відсотки за овердрафтом',
        'insufficient_funds' => 'Комісія за недостатність коштів',
        'penalty_fee' => 'Штрафна комісія',
        'loan_arrangement_fee' => 'Комісія за надання кредиту',
    ],

    'cp_card' => [
        'aria' => 'Контрагент: :name',
        'recent_aria' => 'Остання активність',
    ],

    'chain_flow' => [
        'aria_prefix' => 'Ланцюг фінансування: ',
        'join' => ' до ',
    ],

    'iban_row' => [
        'label' => 'IBAN',
        'hidden_aria' => 'IBAN приховано — натисни «Показати IBAN», щоб побачити',
        // i18n-review: uk · hidden_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'hidden_aria_touch' => 'IBAN приховано — торкнися «Показати IBAN», щоб побачити',
        'show' => 'Показати IBAN',
        'hide' => 'Сховати IBAN',
    ],

    'privacy_banner' => [
        'aria' => 'Повідомлення про приватність для особистого контакту',
        'body' => '🔒 Це особистий контакт. IBAN та особисті дані приховані за замовчуванням і ніколи не потрапляють в експорт.',
    ],

    'self_stub' => [
        'aria' => 'Не справжній контрагент',
        'heading' => 'Це насправді не контрагент',

        'body_rest_html' => ' з’являється тут, бо фігурує у твоїх транзакціях як ланка фінансування між рахунками. Але це <strong>твій власний рахунок</strong>, а не той, з ким ти проводиш операції.',
        'body2' => 'Відкрий вигляд рахунку, щоб побачити баланс, виписки та повну історію транзакцій.',
        'open_cta' => 'Відкрити вигляд рахунку — :name →',
        'hide_cta' => 'Сховати з цього списку',
        'recent_legs' => 'Останні міжрахункові ланки',
    ],
];
