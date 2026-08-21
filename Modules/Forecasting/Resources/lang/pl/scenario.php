<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Edytor scenariusza — :name',
    'rename_aria' => 'Zmień nazwę scenariusza',
    'save' => 'Zapisz',
    'save_changes' => 'Zapisz zmiany',
    'cancel' => 'Anuluj',
    'rename' => 'Zmień nazwę',
    'confirm_delete' => 'Potwierdź usunięcie',
    'delete_scenario' => 'Usuń scenariusz',
    'delete_confirm' => 'Usunąć ten scenariusz?',

    'mutations_count' => 'Zmiany (:count)',
    'no_mutations' => 'Brak zmian. Dodaj jedną poniżej, aby zobaczyć, jak ten scenariusz wypada na tle punktu odniesienia.',
    'editing' => 'Edycja — :kind',
    'edit' => 'Edytuj',
    'remove' => 'Usuń',

    'add_mutation' => '+ Dodaj zmianę',
    'add_to_scenario' => 'Dodaj do scenariusza',
    'pick_kind' => 'Wybierz rodzaj zmiany:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Anuluj serię',
            'desc' => 'Usuń każde prognozowane wystąpienie zatwierdzonej serii.',
        ],
        'add_one_off' => [
            'title' => 'Dodaj jednorazowe obciążenie lub wpływ',
            'desc' => 'Pojedyncze hipotetyczne zdarzenie w konkretnym dniu.',
        ],
        'add_recurring' => [
            'title' => 'Dodaj serię cykliczną',
            'desc' => 'Hipotetyczna nowa subskrypcja lub źródło przychodu.',
        ],
        'change_series_amount' => [
            'title' => 'Zmień kwotę serii',
            'desc' => 'Zamodeluj podwyżkę lub obniżkę ceny istniejącej serii.',
        ],
        'shift_series_date' => [
            'title' => 'Przesuń datę serii',
            'desc' => 'Przesuń w przód następne lub wszystkie kolejne wystąpienia.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Seria do anulowania',
        'pick_series' => '— wybierz serię —',
        'date' => 'Data',
        'amount' => 'Kwota',
        'currency' => 'Waluta',
        'direction' => 'Kierunek',
        'expense_long' => 'Wydatek (pieniądze wychodzą)',
        'income_long' => 'Przychód (pieniądze wpływają)',
        'note' => 'Notatka (opcjonalnie)',
        'start_date' => 'Data początkowa',
        'expense' => 'Wydatek',
        'income' => 'Przychód',
        'cadence' => 'Częstotliwość',
        'cadence_weekly' => 'Co tydzień',
        'cadence_monthly' => 'Co miesiąc',
        'cadence_quarterly' => 'Co kwartał',
        'cadence_yearly' => 'Co rok',
        'series' => 'Seria',
        'new_amount' => 'Nowa kwota',
        'new_next_date' => 'Nowa data następnego wystąpienia',
        'scope' => 'Zakres',
        'scope_legend' => 'Które wystąpienia przesunąć',
        'scope_next' => 'Tylko następne wystąpienie',
        'scope_all' => 'Wszystkie kolejne wystąpienia',
    ],

    'whatif' => [
        'trigger' => 'Zamodeluj wariant',
        'menu_aria' => 'Zamodeluj wariant dla: :name',
        'model_cancellation' => 'Zamodeluj anulowanie',
        'model_amount_change' => 'Zamodeluj zmianę kwoty…',
        'amount_dialog_aria' => 'Zamodeluj zmianę kwoty dla: :name',
        'current_amount' => 'Bieżąca kwota',
        'new_amount' => 'Nowa kwota',
    ],

    'series_name_fallback' => 'seria',

    'summary' => [
        'cancel' => 'Anulowanie: :name',
        'series_fallback' => 'seria #:id',
        'one_off' => ':amount :currency dnia :date',
        'recurring' => ':amount :currency :cadence od :date',
        'change_amount' => ':name: nowa kwota :amount',
        'shift' => ':name: przesunięcie :scope na :date',
        'scope_all' => 'wszystkich kolejnych',
        'scope_next' => 'następnego',
    ],

    'toast' => [
        'created' => 'Utworzono scenariusz „:name”.',
        'deleted' => 'Scenariusz usunięty.',
        'renamed' => 'Nazwa scenariusza zmieniona.',
        'mutation_added' => 'Zmiana dodana.',
        'mutation_updated' => 'Zmiana zaktualizowana.',
        'mutation_removed' => 'Zmiana usunięta. Cofnij',
    ],

    'errors' => [
        'name_empty' => 'Nazwa scenariusza nie może być pusta.',
        'name_too_long' => 'Nazwa scenariusza może mieć najwyżej :max znaków.',
        'name_taken' => 'Scenariusz o tej nazwie już istnieje.',
        'pick_kind_first' => 'Najpierw wybierz rodzaj zmiany.',
        'amount_positive' => 'Kwota musi być liczbą dodatnią.',
    ],
];
