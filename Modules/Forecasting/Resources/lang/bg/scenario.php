<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Редактор на сценарии — :name',
    'rename_aria' => 'Преименувай сценария',
    'save' => 'Запази',
    'save_changes' => 'Запази промените',
    'cancel' => 'Отказ',
    'rename' => 'Преименувай',
    'confirm_delete' => 'Потвърди изтриването',
    'delete_scenario' => 'Изтрий сценария',
    'delete_confirm' => 'Да се изтрие ли този сценарий?',

    'mutations_count' => 'Промени (:count)',
    'no_mutations' => 'Още няма промени. Добави една по-долу, за да видиш как този сценарий се сравнява с базовия.',
    'editing' => 'Редактиране — :kind',
    'edit' => 'Редактирай',
    'remove' => 'Премахни',

    'add_mutation' => '+ Добави промяна',
    'add_to_scenario' => 'Добави към сценария',
    'pick_kind' => 'Избери вид промяна:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Прекрати поредица',
            'desc' => 'Премахва всяко прогнозно появяване на одобрена поредица.',
        ],
        'add_one_off' => [
            'title' => 'Добави еднократно плащане или постъпление',
            'desc' => 'Едно хипотетично събитие на конкретна дата.',
        ],
        'add_recurring' => [
            'title' => 'Добави повтаряща се поредица',
            'desc' => 'Хипотетичен нов абонамент или източник на приход.',
        ],
        'change_series_amount' => [
            'title' => 'Промени сумата на поредица',
            'desc' => 'Моделирай повишаване или спад на цената при съществуваща поредица.',
        ],
        'shift_series_date' => [
            'title' => 'Измести датата на поредица',
            'desc' => 'Премества напред следващото или всички последващи появявания.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Поредица за прекратяване',
        'pick_series' => '— избери поредица —',
        'date' => 'Дата',
        'amount' => 'Сума',
        'currency' => 'Валута',
        'direction' => 'Посока',
        'expense_long' => 'Разход (изходящи пари)',
        'income_long' => 'Приход (входящи пари)',
        'note' => 'Бележка (по избор)',
        'start_date' => 'Начална дата',
        'expense' => 'Разход',
        'income' => 'Приход',
        'cadence' => 'Честота',
        'cadence_weekly' => 'Седмично',
        'cadence_monthly' => 'Месечно',
        'cadence_quarterly' => 'Тримесечно',
        'cadence_yearly' => 'Годишно',
        'series' => 'Поредица',
        'new_amount' => 'Нова сума',
        'new_next_date' => 'Нова следваща дата',
        'scope' => 'Обхват',
        'scope_legend' => 'Кои появявания да се преместят',
        'scope_next' => 'Само следващото появяване',
        'scope_all' => 'Всички последващи появявания',
    ],

    'whatif' => [
        'trigger' => 'Моделирай „какво ако“',
        'menu_aria' => 'Моделирай „какво ако“ за :name',
        'model_cancellation' => 'Моделирай прекратяване',
        'model_amount_change' => 'Моделирай промяна на сумата…',
        'amount_dialog_aria' => 'Моделирай промяна на сумата за :name',
        'current_amount' => 'Текуща сума',
        'new_amount' => 'Нова сума',
    ],

    'series_name_fallback' => 'поредица',

    'summary' => [
        'cancel' => 'Прекрати :name',
        'series_fallback' => 'поредица №:id',
        'one_off' => ':amount :currency на :date',
        'recurring' => ':amount :currency :cadence от :date',
        'change_amount' => ':name: нова сума :amount',
        'shift' => ':name: измести :scope на :date',
        'scope_all' => 'всички последващи',
        'scope_next' => 'следващото',
    ],

    'toast' => [
        'created' => 'Сценарият „:name“ е създаден.',
        'deleted' => 'Сценарият е изтрит.',
        'renamed' => 'Сценарият е преименуван.',
        'mutation_added' => 'Промяната е добавена.',
        'mutation_updated' => 'Промяната е обновена.',
        'mutation_removed' => 'Промяната е премахната. Отмени',
    ],

    'errors' => [
        'name_empty' => 'Името на сценария не може да е празно.',
        'name_too_long' => 'Името на сценария трябва да е най-много :max знак.|Името на сценария трябва да е най-много :max знака.',
        'name_taken' => 'Вече съществува сценарий с това име.',
        'pick_kind_first' => 'Първо избери вид промяна.',
        'amount_positive' => 'Сумата трябва да е положително число.',
    ],
];
