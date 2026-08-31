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
        'name' => 'Karta ICS',
        'heading' => 'Nazwij konto karty ICS.',
        'help' => 'To pierwszy import danych ICS. Nadaj tej karcie nazwę, aby wyświetlała się spójnie w całej aplikacji.',
        'placeholder' => 'np. Karta ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Nazwij konto PayPal.',
        'help' => 'To pierwszy import danych PayPal. Nadaj temu portfelowi nazwę, aby wyświetlał się spójnie w całej aplikacji.',
        'placeholder' => 'np. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Nazwij konto Google Play.',
        'help' => 'To pierwszy import paragonu Google Play. Nadaj temu kontu nazwę, aby wyświetlało się spójnie w całej aplikacji.',
        'placeholder' => 'np. Google Play',
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

    'rows_shown' => 'Pokazane wiersze: :shown z :total',

    'show_more' => 'Pokaż więcej wierszy',

    'errors' => [
        'app_locked' => 'Odblokuj aplikację, aby zaimportować: kluczy szyfrowania nie można użyć, gdy jest zablokowana.',
        'archive_holds_one_message' => 'Ten plik to pojedyncza wiadomość e-mail, a nie archiwum skrzynki, więc odczytany jako archiwum nic nie zawiera. Wgraj go ponownie z formatem Wiadomość e-mail.',
        'email_file_is_an_archive' => 'Ten plik to archiwum skrzynki: zawiera więcej niż jedną wiadomość, a odczytany jako jedna wiadomość wziąłby z niego tylko pierwszą. Wgraj go ponownie z formatem Archiwum skrzynki.',
        'file_stopped_short' => 'Wiersz nagłówka pasował, więc format jest właściwy. Odczyt zatrzymał się przed końcem pliku. Powoduje to jeden nieczytelny wiersz, a także plik zbyt duży dla tego urządzenia. Spróbuj krótszego zakresu dat.',
        'file_unreadable' => 'Nie udało się odczytać tego pliku.',
        'file_unreadable_detail' => 'Aplikacja nie mogła odczytać tego pliku (:code). Pełne szczegóły znajdują się w dzienniku aplikacji; podaj ten kod, zgłaszając problem.',
        'iban_not_in_preview' => 'Ten IBAN nie należy do bieżącego podglądu.',
        'not_an_email_file' => 'Ten plik nie jest ani wiadomością e-mail, ani archiwum skrzynki, więc nie ma w nim czego odczytać jako paragon. Wybierz typ importu i format pasujące do twojego pliku.',
        'pdf_has_no_text_layer' => 'Ten PDF nie zawiera tekstu — to skan albo zdjęcie wyciągu, więc nie ma w nim czego odczytać. Pobierz sam wyciąg ze swojego banku albo użyj eksportu CSV.',
        'pdf_password_protected' => 'Ten PDF jest chroniony hasłem, więc żaden czytnik go nie otworzy. Zapisz w swojej przeglądarce PDF kopię bez zabezpieczenia i zaimportuj ją.',
        'pdf_reader_unavailable' => 'Ta wersja aplikacji nie ma żadnego czytnika PDF, więc wyciągu PDF nie da się tu otworzyć. Zaimportuj ten plik na innym urządzeniu albo użyj eksportu CSV z banku.',
        'row_belongs_to_another_statement' => 'Ten wiersz należy do transakcji w innym pliku wyciągu. Zaimportuj również ten wyciąg — oba są odczytywane razem.',
        'row_unreadable' => 'Nie udało się odczytać tego wiersza.',
        'row_unreadable_detail' => 'Aplikacja nie mogła odczytać tego wiersza (:code). Pełne szczegóły znajdują się w dzienniku aplikacji; podaj ten kod, zgłaszając problem.',
        'unknown_account' => 'Ten wiersz należy do konta, któremu nie nadano jeszcze nazwy.',
    ],

    'receipts' => [
        'heading' => 'Ten plik został odczytany jako e-mail',
        'saved' => 'Co zawierał, jest wypisane niżej, a każda wiadomość została zachowana.',
        'none_imported' => 'Nic z tego nie stało się transakcją, więc do księgi nic nie trafiło.',
        'shown' => 'Pokazane wiadomości: :shown z :total',
        'no_subject' => 'Bez tematu',

        'state' => [
            'read' => 'Odczytano jako płatność — potwierdź ten import, aby trafiła do księgi.',
            'not_a_payment' => 'To nie płatność. Ta wiadomość o czymś informuje, zamiast potwierdzać płatność.',
            'unreadable' => 'Zachowano. Aplikacja czyta paragony od tego nadawcy, ale w tej wiadomości nie znalazła kwoty, sprzedawcy ani numeru referencyjnego.',
            'unknown_sender' => 'Zachowano. Aplikacja nie czyta paragonów od tego nadawcy, więc nic z wiadomości nie wzięła.',
        ],
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
