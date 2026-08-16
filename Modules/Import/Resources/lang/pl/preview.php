<?php

declare(strict_types=1);

return [
    'page_title' => 'Podgląd importu',
    'heading' => 'Podgląd importu',
    'discard' => 'Odrzuć import',
    'confirm' => 'Potwierdź import',
    'subtitle' => 'Przejrzyj wczytane wiersze. Nic nie trafi do księgi, dopóki nie potwierdzisz.',

    'expired_html' => 'Podgląd wygasł. <a href="/imports/new" class="underline">Wgraj plik ponownie</a>, aby spróbować jeszcze raz.',

    'save_name' => 'Zapisz nazwę',
    'account_name_label' => 'Nazwa konta',
    'account_placeholder' => 'np. Główne konto oszczędnościowe',
    'rename_aria' => 'Zmień nazwę tego kontrahenta',

    'unknown_iban_prefix' => 'Znaleźliśmy nieznany IBAN:',
    'unknown_iban_suffix' => 'Nazwij to konto.',

    'ics' => [
        'heading' => 'Nazwij konto karty ICS.',
        'help' => 'To pierwszy import danych ICS. Nadaj tej karcie nazwę, aby wyświetlała się spójnie w całej aplikacji.',
        'placeholder' => 'np. Karta ICS',
    ],

    'paypal' => [
        'heading' => 'Nazwij konto PayPal.',
        'help' => 'To pierwszy import danych PayPal. Nadaj temu portfelowi nazwę, aby wyświetlał się spójnie w całej aplikacji.',
        'placeholder' => 'np. PayPal',
    ],

    'col_date' => 'Data',
    'col_funding_source' => 'Źródło finansowania',
    'col_counterparty' => 'Kontrahent',
    'col_amount' => 'Kwota',
    'col_status' => 'Status',

    'status' => [
        'new' => 'Nowa',
        'new_title' => 'Zostanie dodana do księgi.',
        'duplicate' => 'Duplikat',
        'duplicate_title' => 'Już zaimportowana — zostanie pominięta.',
        'enriched' => 'Wzbogacona',
        'enriched_title' => 'Istniejący wiersz zostanie uzupełniony o mocniejsze odniesienie do źródła.',
        'error' => 'Błąd',
    ],

    'chain' => [
        'heading' => 'Rozwiązywanie łańcuchów…',
        'pending' => 'W kolejce. Rozwiązywanie łańcuchów wkrótce się rozpocznie.',
        'running' => 'Trwa łączenie łańcuchów finansowania i rozkładanie rozliczeń z wyciągu.',
        'failed_prefix' => 'Rozwiązywanie łańcuchów nie powiodło się:',
        'unknown_error' => 'wystąpił nieznany błąd',
        'open_horizon' => 'Otwórz Horizon',
        'failed_suffix' => 'aby ponowić lub sprawdzić.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Ten IBAN nie należy do bieżącego podglądu.',
    ],
];
