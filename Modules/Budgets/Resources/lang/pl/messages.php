<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Budżety',
        'subtitle' => 'Przypisz wszystko co do grosza — :period.',
    ],

    'nav' => [
        'prev_aria' => 'Poprzedni okres',
        'next_aria' => 'Następny okres',
    ],

    'ready' => [
        'label' => 'Do przypisania',
        'overassigned' => 'Przypisano więcej, niż jest dostępne — zmniejsz kopertę albo poczekaj na kolejne wpływy.',
    ],

    'empty' => [
        'nothing_assigned_heading' => 'Nic jeszcze nie przypisano',
        'copy_hint' => 'Skopiuj plan z zeszłego miesiąca albo kliknij komórkę poniżej, aby zacząć przypisywać.',
        // i18n-review: pl · empty.copy_hint_touch — the same line for a
        // touch screen; check the verb governs this case.
        'copy_hint_touch' => 'Skopiuj plan z zeszłego miesiąca albo dotknij komórki poniżej, aby zacząć przypisywać.',
        'first_hint' => 'Kliknij komórkę poniżej, aby zacząć przypisywać w pierwszym miesiącu.',
        // i18n-review: pl · empty.first_hint_touch — the same line for a
        // touch screen; check the verb governs this case.
        'first_hint_touch' => 'Dotknij komórki poniżej, aby zacząć przypisywać w pierwszym miesiącu.',
        'copy_button' => 'Kopiuj zeszły miesiąc',
    ],

    'no_categories' => [
        'heading' => 'Brak kategorii wydatków',
        'body' => 'Dodaj kategorię wydatków, aby zacząć przypisywać do niej pieniądze.',
    ],

    'table' => [
        'category' => 'Kategoria',
        'assigned' => 'Przypisano',
        'carried_in' => 'Przeniesiono',
        'moved' => 'Przesunięto',
        'spent' => 'Wydano',
        'available' => 'Dostępne',
        'if_overspent' => 'Przy przekroczeniu',
        'notify_at' => 'Powiadom przy',
        'actions' => 'Akcje',
    ],

    'badge' => [
        'carries_negative' => 'Przenosi minus',
        'unconverted_aria' => 'Wydatki w walucie bez dostępnego kursu nie są tu liczone — zobacz pulpit',
        'unconverted_title' => 'Wydatki bez dostępnego kursu nie są tu liczone — zobacz pulpit',
        'over_budget' => 'Ponad budżet: :count',
    ],

    'row' => [
        'assigned_aria' => 'Przypisana kwota — kategoria: :category',
        'overspend_aria' => 'Przy przekroczeniu — kategoria: :category',
        'notify_aria' => 'Powiadom przy procencie wykorzystania — kategoria: :category',
        'move_money' => 'Przenieś pieniądze',
        'move' => 'Przenieś',
    ],

    'overspend' => [
        'reduce' => 'Zmniejsz kwotę do przypisania w przyszłym miesiącu',
        'carry' => 'Przenieś minus w tej kopercie',
    ],

    'history' => [
        'show' => 'Pokaż historię ↓',
        'hide' => 'Ukryj historię ↑',
        'moved_from' => 'Przeniesiono z kategorii: :category',
        'moved_to' => 'Przeniesiono do kategorii: :category',
        'moved_unreadable' => 'Przeniesiono z kategorią: :category przez nowszą wersję Beatrax',
        'undo' => 'Cofnij',
    ],

    'phone' => [
        'spent' => 'Wydano :amount',
        'carried_in' => 'Przeniesiono :amount',
        'moved' => 'Przesunięto :amount',
        'available' => 'Dostępne :amount',
        'notify_at' => 'Powiadom przy',
    ],

    'modal' => [
        'move_from' => 'Przenieś z: :name',
        'move_from_fallback' => 'koperta',
        'move_to' => 'Przenieś do',
        'no_other' => 'Brak innych kopert',
        'select' => 'Wybierz kopertę',
        'amount' => 'Kwota',
        'available_in' => 'Dostępne w kopercie :name: :amount',
        'note' => 'Notatka (opcjonalnie)',
        'note_placeholder' => 'np. Pokrycie przekroczenia na jedzeniu',
        'cancel' => 'Anuluj',
        'move_funds' => 'Przenieś środki',
    ],

    'glance' => [
        'see_all' => 'Zobacz wszystkie →',
    ],

    'notices' => [
        'invalid_amount' => 'Podaj prawidłową kwotę.',
        'threshold_range' => 'Podaj liczbę całkowitą od 1 do 200.',
        'copied_last_month' => 'Skopiowano plan z zeszłego miesiąca.',
        'choose_envelope' => 'Wybierz kopertę, do której trafią pieniądze.',
        'amount_positive' => 'Podaj kwotę większą od zera.',
        'move_failed' => 'Nie udało się wykonać przeniesienia — spróbuj ponownie.',
        'money_moved' => 'Pieniądze przeniesione.',
        'move_undone' => 'Przeniesienie cofnięte.',
    ],

    'errors' => [
        'assigned_negative' => 'Przypisana kwota nie może być ujemna.',
        'invalid_overspend_mode' => 'Nieprawidłowy tryb przekroczenia.',
        'threshold_range' => 'Próg powiadomienia musi mieścić się w zakresie od 1 do 200.',
        'same_envelope' => 'Koperta źródłowa i docelowa muszą być różne.',
        'non_positive_amount' => 'Nieprawidłowa lub niedodatnia kwota.',
        'category_not_found' => 'Nie znaleziono kategorii albo użytkownik nie ma do niej dostępu.',
    ],
];
