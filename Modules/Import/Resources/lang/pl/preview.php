<?php

declare(strict_types=1);

return [
    'page_title' => 'Podgląd importu',
    'heading' => 'Podgląd importu',
    'discard' => 'Odrzuć import',
    'confirm' => 'Potwierdź import',
    'subtitle' => 'Przejrzyj wczytane wiersze. Nic nie trafi do księgi, dopóki nie potwierdzisz.',

    'already_imported' => 'Ten plik został już zaimportowany.',

    'already_imported_link' => 'Zobacz wynik importu',

    'expired_html' => 'Podgląd wygasł. <a href="/imports/new" class="underline">Wgraj plik ponownie</a>, aby spróbować jeszcze raz.',

    'save_name' => 'Zapisz nazwę',
    'account_name_label' => 'Nazwa konta',
    'account_placeholder' => 'np. Główne konto oszczędnościowe',
    'rename_aria' => 'Zmień nazwę tego kontrahenta',

    'unknown_iban_prefix' => 'Znaleźliśmy nieznany IBAN:',

    'unknown_account_prefix' => 'Znaleźliśmy nieznane konto:',
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
        'failed_detail' => 'szczegóły są w dzienniku zadań',
        'open_horizon' => 'Otwórz Horizon',
        'failed_suffix' => 'aby ponowić lub sprawdzić.',
    ],

    'errors' => [
        'app_locked' => 'Odblokuj aplikację, aby zaimportować: kluczy szyfrowania nie można użyć, gdy jest zablokowana.',
        'file_stopped_short' => 'Wiersz nagłówka pasował, więc format jest właściwy. Odczyt zatrzymał się przed końcem pliku. Powoduje to jeden nieczytelny wiersz, a także plik zbyt duży dla tego urządzenia. Spróbuj krótszego zakresu dat.',
        'file_unreadable' => 'Nie udało się odczytać tego pliku.',
        'iban_not_in_preview' => 'Ten IBAN nie należy do bieżącego podglądu.',
        'pdf_reader_unavailable' => 'Wyciągi PDF wymagają programu pdftotext, którego tu nie zainstalowano. Zaimportuj ten plik na komputerze, który go ma, albo użyj eksportu CSV z banku.',
        'row_unreadable' => 'Nie udało się odczytać tego wiersza.',
        'unknown_account' => 'Ten wiersz należy do konta, któremu nie nadano jeszcze nazwy.',
    ],

    'failed' => [
        'heading' => 'Nie udało się odczytać tego pliku',
        'no_rows' => 'W tym pliku nie znaleziono transakcji, więc nie ma czego importować.',
        'nothing_read' => 'Niczego w tym pliku nie udało się odczytać jako transakcji, więc nie ma czego importować.',
        'every_row' => 'Żadnego wiersza w tym pliku nie udało się odczytać, więc nie ma czego importować. Każdy wiersz jest poniżej wraz z powodem.',
        'likely_cause' => 'Najczęściej wiersz nagłówka nie pasuje do wybranego źródła. Sprawdź bank i format na ekranie wysyłania albo pobierz wyciąg z banku ponownie.',
        'truncated_heading' => 'Udało się odczytać tylko część tego pliku',
        'truncated' => 'Odczyt zatrzymał się w połowie pliku. Wszystko po tym miejscu nie zostało odczytane i nie zostanie zaimportowane.',
        'some_rows' => 'Niektórych wierszy nie udało się odczytać. Są oznaczone poniżej i zostaną pominięte; potwierdzenie zaimportuje resztę.',
        'detail_label' => 'Co zgłosił analizator:',
        'rows_read_label' => 'Odczytane wiersze',
        'rows_skipped_label' => 'Pominięte wiersze',
    ],
];
