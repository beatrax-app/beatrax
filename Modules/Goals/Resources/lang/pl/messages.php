<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Cele',
        'subtitle' => 'Śledź postęp w realizacji celów oszczędnościowych.',
        'add_goal' => 'Dodaj cel',
    ],

    'empty' => [
        'heading' => 'Brak celów',
        'body' => 'Ustaw kwotę docelową i datę, aby zacząć śledzić postęp oszczędzania.',
        'add_first' => 'Dodaj pierwszy cel',
    ],

    'status' => [
        'overdue' => 'Po terminie',
        'reached' => 'Osiągnięty',
        'completed' => 'Zakończony',
        'archived' => 'Zarchiwizowany',
    ],

    'row' => [
        'edit' => 'Edytuj',
    ],

    'progress' => [
        'aria' => ':name: ukończono :pct%',
    ],

    'card' => [
        'target_date' => 'Data docelowa: :date',
    ],

    'projection' => [
        'target_reached' => 'Cel osiągnięty',
        'closed_short' => 'Zamknięte przed osiągnięciem celu',
        'add_contributions' => 'Dodaj wpłaty, aby zobaczyć prognozę',
        'not_enough_history' => 'Za mało historii, aby przewidzieć datę',
        'no_recent_contributions' => 'Brak niedawnych wpłat, na których można oprzeć prognozę',
        'too_far_to_date' => 'W tym tempie zbyt daleko, by podać datę',
        'est' => 'Szac. :date ·',
        'projection_note' => '(prognoza)',
        'projected' => 'Prognoza: :date',
    ],

    'archive' => [
        'confirm_question' => 'Zarchiwizować ten cel?',
        'close' => 'Zamknij',
        'confirm_aria' => 'Potwierdź archiwizację — cel: :name',
        'archive' => 'Archiwizuj',
    ],

    'actions' => [
        'more_aria' => 'Więcej działań — cel: :name',
        'mark_complete' => 'Oznacz jako zakończony',
        'mark_complete_caption' => 'Oznacz',
        'archive' => 'Archiwizuj',
        'restore' => 'Przywróć',
    ],

    'archived_disclosure' => 'Zarchiwizowany cel (:count)|Zarchiwizowane cele (:count)|Zarchiwizowanych celów (:count)',

    'form' => [
        'title_edit' => 'Edytuj cel',
        'title_create' => 'Utwórz cel oszczędnościowy',
        'subtitle_edit' => 'Zaktualizuj nazwę, kwotę docelową, datę lub powiązaną skarbonkę.',
        'subtitle_create' => 'Ustaw kwotę docelową i datę, aby śledzić postęp oszczędzania.',
        'name' => 'Nazwa',
        'name_placeholder' => 'np. Fundusz awaryjny',
        'target_amount' => 'Kwota docelowa (:currency)',
        'target_date' => 'Data docelowa',
        'linked_pot' => 'Powiązana skarbonka (opcjonalnie)',
        'no_pot' => 'Bez skarbonki — użyj śledzenia przelewów',
        'linked_pot_help' => 'Po powiązaniu postęp tego celu wynika z salda skarbonki.',
        'save_changes' => 'Zapisz zmiany',
        'save_goal' => 'Zapisz cel',
        'close' => 'Zamknij',
    ],

    'summary' => [
        'see_all' => 'Zobacz wszystkie →',
        'no_goals' => 'Brak celów.',
        'add_first' => 'Dodaj pierwszy cel →',
    ],

    'notices' => [
        'goal_created' => 'Cel utworzony.',
        'goal_updated' => 'Cel zaktualizowany.',
        'goal_marked_complete' => 'Cel oznaczony jako zakończony.',
        'goal_archived' => 'Cel zarchiwizowany.',
        'goal_restored' => 'Cel przywrócony.',
    ],

    'errors' => [
        'name' => 'Podaj nazwę celu.',
        'date' => 'Wybierz datę docelową.',
        'date_invalid' => 'Wybierz prawdziwą datę.',
        'date_before_start' => 'Wybierz datę w dniu rozpoczęcia celu lub późniejszą.',
        'generic' => 'Nie udało się zapisać celu. Sprawdź pola i spróbuj ponownie.',
        'amount' => 'Podaj prawidłową kwotę większą od zera.',
        'pot_linked_category' => 'Ta skarbonka jest powiązana z kategorią. Najpierw usuń to powiązanie na stronie Skarbonki.',
        'pot_already_linked' => 'Ta skarbonka już finansuje inny cel. Najpierw usuń tam powiązanie.',
        'pot_missing' => 'Ta skarbonka nie jest już dostępna. Wybierz inną albo zostaw ten cel bez powiązania.',
    ],
];
