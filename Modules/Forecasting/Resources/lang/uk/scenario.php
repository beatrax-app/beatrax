<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Редактор сценарію — :name',
    'rename_aria' => 'Перейменувати сценарій',
    'save' => 'Зберегти',
    'save_changes' => 'Зберегти зміни',
    'cancel' => 'Скасувати',
    'rename' => 'Перейменувати',
    'confirm_delete' => 'Підтвердити видалення',
    'delete_scenario' => 'Видалити сценарій',
    'delete_confirm' => 'Видалити цей сценарій?',

    'mutations_count' => 'Зміни (:count)',
    'no_mutations' => 'Змін ще немає. Додай одну нижче, щоб побачити, як цей сценарій відрізняється від базового.',
    'editing' => 'Редагування — :kind',
    'edit' => 'Редагувати',
    'remove' => 'Видалити',

    'add_mutation' => '+ Додати зміну',
    'add_to_scenario' => 'Додати до сценарію',
    'pick_kind' => 'Обери тип зміни:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Скасувати серію',
            'desc' => 'Прибрати всі прогнозовані повторення затвердженої серії.',
        ],
        'add_one_off' => [
            'title' => 'Додати разове списання або надходження',
            'desc' => 'Одна гіпотетична подія на конкретну дату.',
        ],
        'add_recurring' => [
            'title' => 'Додати регулярну серію',
            'desc' => 'Гіпотетична нова підписка або джерело доходу.',
        ],
        'change_series_amount' => [
            'title' => 'Змінити суму серії',
            'desc' => 'Змоделювати підвищення або зниження ціни наявної серії.',
        ],
        'shift_series_date' => [
            'title' => 'Зсунути дату серії',
            'desc' => 'Перенести наступне або всі подальші повторення вперед.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Серія для скасування',
        'pick_series' => '— обери серію —',
        'date' => 'Дата',
        'amount' => 'Сума',
        'currency' => 'Валюта',
        'direction' => 'Напрямок',
        'expense_long' => 'Витрата (гроші з рахунку)',
        'income_long' => 'Дохід (гроші на рахунок)',
        'note' => 'Нотатка (необов’язково)',
        'start_date' => 'Дата початку',
        'expense' => 'Витрата',
        'income' => 'Дохід',
        'cadence' => 'Періодичність',
        'cadence_weekly' => 'Щотижня',
        'cadence_monthly' => 'Щомісяця',
        'cadence_quarterly' => 'Щокварталу',
        'cadence_yearly' => 'Щороку',
        'series' => 'Серія',
        'new_amount' => 'Нова сума',
        'new_next_date' => 'Нова наступна дата',
        'scope' => 'Обсяг',
        'scope_legend' => 'Які повторення перемістити',
        'scope_next' => 'Лише наступне повторення',
        'scope_all' => 'Усі подальші повторення',
    ],

    'whatif' => [
        'trigger' => 'Змоделювати «що якщо»',
        'menu_aria' => 'Змоделювати «що якщо» — :name',
        'model_cancellation' => 'Змоделювати скасування',
        'model_amount_change' => 'Змоделювати зміну суми…',
        'amount_dialog_aria' => 'Змоделювати зміну суми — :name',
        'current_amount' => 'Поточна сума',
        'new_amount' => 'Нова сума',
    ],

    'series_name_fallback' => 'серія',

    'summary' => [
        'cancel' => 'Скасувати: :name',
        'series_fallback' => 'серія #:id',
        'one_off' => ':amount :currency — :date',
        'recurring' => ':amount :currency :cadence від :date',
        'change_amount' => ':name: нова сума :amount',
        'shift' => ':name: зсув :scope на :date',
        'scope_all' => 'усі подальші',
        'scope_next' => 'наступне',
    ],

    'toast' => [
        'created' => 'Сценарій «:name» створено.',
        'deleted' => 'Сценарій видалено.',
        'renamed' => 'Сценарій перейменовано.',
        'mutation_added' => 'Зміну додано.',
        'mutation_updated' => 'Зміну оновлено.',
        'mutation_removed' => 'Зміну видалено. Скасувати',
    ],

    'errors' => [
        'name_empty' => 'Назва сценарію не може бути порожньою.',
        'name_too_long' => 'Назва сценарію має містити не більше ніж :max символів.',
        'name_taken' => 'Сценарій із такою назвою вже існує.',
        'pick_kind_first' => 'Спершу обери тип зміни.',
        'amount_positive' => 'Сума має бути додатним числом.',
    ],
];
