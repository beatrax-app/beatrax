<?php

declare(strict_types=1);

return [
    'page_title' => 'Skarbonki · Beatrax',
    'heading' => 'Skarbonki',
    'subtitle' => 'Wirtualne salda cząstkowe, które zawsze sumują się do rzeczywistego salda konta.',
    'add_pot' => 'Dodaj skarbonkę',

    'pot_fallback' => 'skarbonka',

    'empty' => [
        'heading' => 'Brak skarbonek',
        'body' => 'Twórz wirtualne salda cząstkowe w dowolnym koncie, aby porządkować pieniądze bez rzeczywistego przelewu bankowego.',
        'cta' => 'Dodaj pierwszą skarbonkę',
        'no_accounts_cta' => 'Zaimportuj wyciąg',
    ],

    'common' => [
        'cancel' => 'Anuluj',
        'amount' => 'Kwota',
        'note_optional' => 'Notatka (opcjonalnie)',
    ],

    'actions' => [
        'fund' => 'Zasil',
        'move' => 'Przenieś',
        'edit' => 'Edytuj',
        'withdraw' => 'Wypłać',
        'archive' => 'Archiwizuj',
        'restore' => 'Przywróć',
    ],

    'recon' => [
        'over_allocated' => 'Skarbonki przekraczają rzeczywiste saldo o :amount — zrównoważ je, aby to naprawić',
        'real_balance' => 'Rzeczywiste saldo:',
        'allocated' => 'Przydzielone:',
        'unallocated' => 'Nieprzydzielone:',
    ],

    'chip' => [
        'goal' => 'Cel:',
        'goal_name_fallback' => 'Cel',
        'category_fallback' => 'Kategoria',
    ],

    'coverage' => [
        'spent' => 'wydano',
        'in_pot' => 'w skarbonce',
    ],

    'archive_confirm' => 'Zarchiwizować tę skarbonkę? Saldo :amount wróci do nieprzydzielonych.',
    'confirm_archive_aria' => 'Potwierdź archiwizację — skarbonka: :name',
    'more_actions_aria' => 'Więcej działań — skarbonka: :name',

    'history' => [
        'show' => 'Pokaż historię ↓',
        'hide' => 'Ukryj historię ↑',
    ],

    'movement' => [
        'fund' => 'Zasilenie',
        'withdraw' => 'Wypłata',
        'moved_from' => 'Przeniesiono ze skarbonki: :name',
        'moved_to' => 'Przeniesiono do skarbonki: :name',
    ],

    'archived' => [
        'toggle' => 'Zarchiwizowane skarbonki (:count)',
        'badge' => 'Zarchiwizowana',
    ],

    'form' => [
        'create_title' => 'Utwórz skarbonkę',
        'edit_title' => 'Edytuj skarbonkę',
        'create_subtitle' => 'Nazwij wirtualne saldo cząstkowe w ramach konta.',
        'edit_subtitle' => 'Zaktualizuj nazwę lub powiązanie tej skarbonki.',
        'name' => 'Nazwa',
        'name_placeholder' => 'np. Fundusz wakacyjny',
        'account' => 'Konto',
        'select_account' => 'Wybierz konto',
        'initial_amount' => 'Kwota początkowa (opcjonalnie)',
        'initial_amount_help' => 'Kwota jest odejmowana od nieprzydzielonych. Zostaw puste, aby utworzyć pustą skarbonkę.',
        'link_to' => 'Powiąż z (opcjonalnie)',
        'link_goal' => 'Cel',
        'link_none' => 'Brak',
        'select_goal' => 'Wybierz cel',
        'save_pot' => 'Zapisz skarbonkę',
        'save_changes' => 'Zapisz zmiany',
    ],

    'fund' => [
        'title' => 'Zasil skarbonkę',
        'heading' => 'Zasil skarbonkę: :name',
        'submit' => 'Zasil skarbonkę',
        'note_placeholder' => 'np. Miesięczne oszczędności',
        'available' => 'Dostępne do przydzielenia: :amount (nieprzydzielone)',
    ],

    'move' => [
        'title' => 'Przenieś środki',
        'heading' => 'Przenieś ze skarbonki: :name',
        'to' => 'Przenieś do',
        'select_pot' => 'Wybierz skarbonkę',
        'no_others_short' => 'Brak innych skarbonek',
        'no_others' => 'Brak innych skarbonek na tym koncie',
        'submit' => 'Przenieś środki',
        'note_placeholder' => 'np. Przelew na wakacje',
    ],

    'withdraw' => [
        'heading' => 'Wypłać ze skarbonki: :name',
        'note_placeholder' => 'np. Wypłata',
    ],

    'available_in' => 'Dostępne (:name): :amount',

    'errors' => [
        'enter_name' => 'Podaj nazwę tej skarbonki.',
        'select_account' => 'Wybierz konto dla tej skarbonki.',
        'amount_exceeds_unallocated' => 'Kwota przekracza nieprzydzielone saldo.',
        'amount_exceeds_unallocated_available' => 'Kwota przekracza nieprzydzielone saldo (dostępne: :amount).',
        'amount_exceeds_pot_balance' => 'Kwota przekracza saldo skarbonki :name (dostępne: :amount).',
        'generic' => 'Nie udało się zapisać koperty. Sprawdź pola i spróbuj ponownie.',
        'amount_invalid' => 'Podaj kwotę większą od zera.',
        'goal_already_linked' => 'Ten cel ma już aktywną powiązaną kopertę. Najpierw ją zarchiwizuj.',
    ],

    'toast' => [
        'pot_created' => 'Skarbonka utworzona.',
        'pot_updated' => 'Skarbonka zaktualizowana.',
        'pot_funded' => 'Skarbonka zasilona.',
        'withdrawn' => 'Wypłacono ze skarbonki.',
        'funds_moved' => 'Środki przeniesione.',
        'pot_archived' => 'Skarbonka zarchiwizowana.',
        'pot_restored' => 'Skarbonka przywrócona.',
    ],
];
