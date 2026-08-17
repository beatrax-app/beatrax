<?php

declare(strict_types=1);

return [
    'page_title' => 'Сортування контрагентів',
    'heading' => 'Розібрати невідомих контрагентів',

    'progress' => ':seen з :total · :percent % · залишилось ~:minutes хв',
    'progress_aria' => 'Прогрес сортування',

    'all_caught_aria' => 'Усі контрагенти позначені',
    'all_caught_heading' => '🎉 Усе розібрано — кожен контрагент позначений.',
    'back_to_index' => 'Назад до контрагентів →',

    'meta' => 'транзакцій: :count · востаннє: :date',

    'suggested_aria' => 'Запропонований збіг',
    'suggestion_medium' => '✨ Можливо, це **:name** — середня впевненість',
    'suggestion_low' => 'Збіг за патерном: **:name** — низька впевненість. Перевір, перш ніж пов’язувати.',
    'suggestion_high' => '✨ Схоже, це **:name** — висока впевненість',

    'reasoning' => ':hits із :total нещодавніх операцій за цим IBAN вказують на :name.',
    'yes_link' => 'Так, пов’язати — :name ↵',
    'no_not' => 'Ні, це не :name',

    'recent_on_iban' => 'Останні транзакції за цим IBAN',
    'no_transactions_yet' => 'Транзакцій поки немає.',

    'label_manually' => 'Або познач вручну',
    'display_name_label' => 'Відображувана назва',
    'display_name_placeholder' => 'Відображувана назва…',
    'type_label' => 'Тип',
    'type_merchant' => 'Продавець',
    'type_personal' => 'Особистий',
    'type_bank' => 'Банк',
    'type_government' => 'Держава',
    'save_label' => 'Зберегти позначку',

    'skip' => 'Поки пропустити',
    'mark_ignored' => 'Позначити як ігнорований',
    'previous' => 'Попередній невідомий',
    'next' => 'Далі',

    'kbd_yes' => 'так',
    'kbd_no' => 'ні',
    'kbd_skip' => 'пропустити',
    'kbd_next' => 'далі',

    'footer' => 'вже позначено: :seen · залишилось: :count',
];
