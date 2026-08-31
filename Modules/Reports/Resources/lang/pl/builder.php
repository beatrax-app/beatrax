<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Bez kategorii',
    'no_counterparty' => 'Brak kontrahenta',
    'unavailable_counterparty' => 'Kontrahent niedostępny na tym urządzeniu',
    'title' => 'Raporty',
    'page_title' => 'Raporty · Beatrax',
    'subtitle' => 'Ułóż raport na podstawie swojej księgi.',
    'controls_aria' => 'Ustawienia raportu',
    'result_aria' => 'Wynik raportu',
    'dismiss' => 'Odrzuć',

    'metric' => [
        'heading' => 'Miara',
        'spend' => 'Wydatki',
        'income' => 'Przychody',
        'net' => 'Netto',
        'net_worth' => 'Wartość netto',
        'fallback' => 'Kwota',
    ],

    'group_by' => 'Grupuj według',

    'dimension' => [
        'category' => 'Kategoria',
        'time_bucket' => 'Przedział czasu',
        'counterparty' => 'Kontrahent',
        'account' => 'Konto',
    ],

    'period' => [
        'heading' => 'Okres',
        'this_month' => 'Ten miesiąc',
        'last_3_months' => 'Ostatnie 3 miesiące',
        'last_6_months' => 'Ostatnie 6 miesięcy',
        'last_12_months' => 'Ostatnie 12 miesięcy',
        'ytd' => 'Od początku roku',
        'this_year' => 'Ten rok',
        'custom' => 'Własny zakres',
        'from' => 'Od',
        'to' => 'Do',
        'error' => [
            'incomplete' => 'Wybierz datę początkową i końcową.',
            'malformed' => 'Podaj poprawną datę w formacie RRRR-MM-DD.',
            'inverted' => 'Data końcowa jest wcześniejsza niż początkowa.',
        ],
    ],

    'currency' => [
        'heading' => 'Waluta',
        'aria' => 'Tryb waluty',
        'base' => 'Bazowa',
        'original' => 'Oryginalna',
    ],

    'granularity' => [
        'heading' => 'Szczegółowość',
        'aria' => 'Szczegółowość czasu',
        'monthly' => 'Miesięcznie',
        'weekly' => 'Tygodniowo',
    ],

    'filters' => [
        'heading' => 'Filtry',
        'net_worth_note' => 'Wartość netto to saldo: działa tylko filtr konta.',
    ],

    'compare' => 'Porównaj z poprzednim okresem',

    'viz' => [
        'heading' => 'Wizualizacja',
        'table' => 'Tabela',
        'bar' => 'Słupkowy',
        'line' => 'Liniowy',
        'donut' => 'Pierścieniowy',
    ],

    'actions' => [
        'update_report' => 'Zaktualizuj raport',
        'save_report' => 'Zapisz raport',
        'report_name' => 'Nazwa raportu',
        'update' => 'Zaktualizuj',
        'save' => 'Zapisz',
        'cancel' => 'Anuluj',
        'export_csv' => 'Eksportuj CSV',
    ],

    'updating' => '… Aktualizowanie',

    'empty' => [
        'heading' => 'Brak wyników dla tego wyboru',
        'body' => 'Spróbuj poszerzyć zakres dat albo usunąć filtr.',
    ],

    'total_prefix' => 'Razem',
    'total' => 'Razem',
    'vs_previous' => 'vs. poprzedni okres',
    'view_transactions' => 'Zobacz transakcje',

    'fx_excluded' => 'nie przeliczono :count konta — brak dostępnego kursu|nie przeliczono :count kont — brak dostępnego kursu|nie przeliczono :count kont — brak dostępnego kursu',

    'group_header' => [
        'category' => 'Kategoria',
        'counterparty' => 'Kontrahent',
        'account' => 'Konto',
        'month' => 'Miesiąc',
        'default' => 'Grupa',
    ],

    'chart' => [
        'other_currencies' => 'Wykres w walucie :currency — :list nie jest pokazane',
        'undrawn' => 'Poza pierścieniem — :amount płynie w drugą stronę',
        'bar_title' => 'Kliknij słupek, aby zobaczyć jego transakcje',
        'line_title' => 'Kliknij punkt, aby zobaczyć jego transakcje',
        'donut_title' => 'Kliknij segment, aby zobaczyć jego transakcje',
    ],

    'flash' => [
        'saved' => 'Raport zapisany.',
        'updated' => 'Raport zaktualizowany.',
    ],

    'filter' => [
        'account' => 'Konto',
        'account_count' => ':count konto|:count konta|:count kont',
        'remove_account' => 'Usuń filtr konta',
        'account_dialog' => 'Filtr konta',

        'category' => 'Kategoria',
        'category_count' => ':count kategoria|:count kategorie|:count kategorii',
        'remove_category' => 'Usuń filtr kategorii',
        'category_dialog' => 'Filtr kategorii',

        'counterparty' => 'Kontrahent',
        'counterparty_count' => ':count kontrahent|:count kontrahenci|:count kontrahentów',
        'remove_counterparty' => 'Usuń filtr kontrahenta',
        'counterparty_dialog' => 'Filtr kontrahenta',

        'amount' => 'Kwota',
        'remove_amount' => 'Usuń filtr kwoty',
        'amount_dialog' => 'Filtr kwoty',
        'dir_both' => 'Oba',
        'dir_in' => 'Wpływy',
        'dir_out' => 'Wypływy',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'Kwota minimalna',
        'max_aria' => 'Kwota maksymalna',
    ],

    'other_movement' => 'Opłaty i korekty (nieuwzględnione powyżej)',
    'other_movement_with_refunds' => 'Opłaty, zwroty i korekty (nieuwzględnione powyżej)',
];
