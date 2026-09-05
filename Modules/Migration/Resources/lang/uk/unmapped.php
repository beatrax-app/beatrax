<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Ціль: :name',
        'category_goal' => 'Ціль для :name',
        'schedule_untitled' => 'Запланована транзакція без назви',
        'transaction' => 'Транзакція: :name · :date · :amount',
        'transaction_unnamed' => 'Транзакція',
        'amount_update' => 'Оновлення суми транзакції',
        'budget_history' => 'Історія бюджету в :currency',
        'budget_file_currency' => 'Валюта файлу бюджету',
        'budget_file_mode' => 'Режим файлу бюджету',
    ],

    'conflict' => [
        'budget_assignment' => 'Розподіл бюджету',
        'budget_for_month' => 'Бюджет: :category · :month',
        'budget_for_category' => 'Бюджет: :category',
        'category_name' => 'Назва категорії',
        'category_name_of' => 'Назва категорії «:name»',
        'account_name' => 'Назва рахунку',
        'account_name_of' => 'Назва рахунку «:name»',
        'transaction_amount' => 'Сума транзакції',
        'transaction_amount_of' => 'Сума: :name',
        'transaction_amount_of_dated' => 'Сума: :name · :date',
        'transaction_description' => 'Опис транзакції',
        'transaction_description_of' => 'Опис: :name',
        'transaction_description_of_dated' => 'Опис: :name · :date',
        'other' => 'Імпортоване значення',
    ],

    'reason' => [
        'fingerprint_collision' => 'Ця транзакція збіглася з іншою, уже записаною транзакцією (однаковий відбиток) і не була імпортована.',

        // i18n-review: uk · reason.split_legs_without_category — "розподіл" is
        // this locale's word for a split (ledger::detail.split) and for a budget
        // assignment (conflict.budget_assignment, budgets::messages), so both
        // senses stand on this screen. A native reader should split them.
        'split_legs_without_category' => ':count частина розподілу з :legs не має категорії, а частину без категорії зберегти не можна. Транзакцію імпортовано на повну суму, і вона чекає в категорії «:uncategorized».|:count частини розподілу з :legs не мають категорії, а частину без категорії зберегти не можна. Транзакцію імпортовано на повну суму, і вона чекає в категорії «:uncategorized».|:count частин розподілу з :legs не має категорії, а частину без категорії зберегти не можна. Транзакцію імпортовано на повну суму, і вона чекає в категорії «:uncategorized».',
        'split_sum_mismatch' => 'Частини розподілу дають у сумі :legs, а транзакція становить :total, тоді як розподіл має точно збігатися зі своєю транзакцією. Транзакцію імпортовано на повну суму, без її частин.',
        'split_unstorable' => 'Beatrax не може зберегти цей розподіл у такому вигляді, тож транзакцію імпортовано окремо, без її частин.',
        'goal_without_target_date' => 'Ця ціль не має цільової дати; Beatrax потребує її, щоб створити ціль заощаджень.',
        'goal_without_name' => 'Ця ціль не має назви; Beatrax потребує її, щоб створити ціль заощаджень.',
        'goal_def_unsupported' => 'categories.goal_def використовує непідтримувану (неплоску) форму шаблона — ціль не імпортовано.',
        'budget_currency_mismatch' => ':count рядок бюджету не імпортовано: твої бюджети ведуться в :envelope, а цей експорт веде бюджет у :source.|:count рядки бюджету не імпортовано: твої бюджети ведуться в :envelope, а цей експорт веде бюджет у :source.|:count рядків бюджету не імпортовано: твої бюджети ведуться в :envelope, а цей експорт веде бюджет у :source.',
        'amount_apply_collision' => 'Нову суму з джерела не вдалося застосувати — вона збігається з відбитком іншої транзакції (той самий рахунок, дата, валюта й контрагент). Залишено без змін.',
        'amount_currency_mismatch' => 'Суми транзакцій не узгоджено: ці транзакції ведуться в :local, а цей експорт подає їх у :source. Залишено без змін.',
        'schedule_unsupported' => 'Beatrax ще не вміє створювати заплановані та регулярні транзакції із зовнішнього джерела — збережено лише як нотатку, а не як активну серію в розділі Регулярні.',
        'saved_report_unsupported' => 'Збережені звіти та конфігурації аналізу не мають відповідника в Beatrax.',
        'assumed_currency' => "Припущено: :currency — у цьому експорті не знайдено жодного рядка 'preferences.currencyCode'.",
        'assumed_budget_type' => "Припущено: :mode — у цьому експорті не знайдено жодного рядка 'preferences.budgetType'.",
        'changed_on_both_sides' => "Від останнього імпорту це змінили і файл джерела, і Beatrax.\nЛокально: :local\nДжерело: :source\nОстанній імпорт: :baseline",
        'take_source' => 'Значення з нового експорту буде застосовано, коли ти підтвердиш — твоє локальне значення буде замінено.',
        'keep_local' => 'Твоє локальне значення буде збережено — значення з нового експорту не буде застосовано.',
        'compared_values' => ":intro\nЛокально: :local · Джерело: :source · Останній імпорт: :baseline",
    ],

    'value' => [
        'none' => '(немає)',
        'quoted' => '«:value»',
    ],
];
