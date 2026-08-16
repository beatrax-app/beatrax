<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Ustawienia',
        'heading' => 'Open banking',
        'subtitle' => 'Automatycznie pobieraj transakcje z ASN lub SNS przez Enable Banking, zewnętrznego agregatora PSD2. Domyślnie wyłączone.',
        'toggle_label' => 'Włącz open banking',
        'toggle_connected' => 'Połączono przez Enable Banking. Bank: :bank.',
        'toggle_off_help' => 'Domyślnie wyłączone. Wymaga jednorazowego potwierdzenia i konfiguracji krok po kroku.',
        'reconfirm_body' => 'Twoje potwierdzenie wygasło, zanim udało się dokończyć łączenie. Potwierdź ponownie, aby dokończyć włączanie open bankingu.',
        'reconfirm_button' => 'Potwierdź ponownie i zakończ',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Zarządzaj open bankingiem',
        'not_connected' => 'Nie połączono żadnego banku. Połącz bank, aby automatycznie importować transakcje.',
        'expired' => 'Zgoda wygasła — potrzebne ponowne połączenie.',
        'connected' => 'Połączono przez Enable Banking. Bank: :bank. Ostatnia synchronizacja: :when.',
        'never' => 'nigdy',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregator',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Status zgody',
        'pill_expired' => 'Wygasła — połącz ponownie',
        'pill_expiring' => 'Wkrótce wygaśnie',
        'pill_connected' => 'Połączono',
        'whats_fetched_label' => 'Co jest pobierane',
        'whats_fetched' => 'Zaksięgowane transakcje + salda, ostatnie 90 dni',
        'last_successful_sync_label' => 'Ostatnia udana synchronizacja',
        'never' => 'Nigdy',
        'last_attempt_label' => 'Ostatnia próba',
        'last_attempt_failed' => ':when — nieudana (:reason)',
        'reason_consent_expired' => 'zgoda wygasła',
        'reason_error' => 'błąd',
        'disconnect_button' => 'Rozłącz',
    ],

    'consent_banner' => [
        'heading' => 'Zgoda wygasła — połącz ponownie',
        'body' => 'Ostatnia udana synchronizacja: :when. Połącz ponownie, aby wznowić automatyczną synchronizację.',
        'never' => 'nigdy',
        'reconnect' => 'Połącz ponownie',
    ],

    'sync' => [
        'review_import' => 'Przejrzyj import',
        'reconnect_first' => 'Najpierw połącz ponownie',
        'auto_caption' => 'Synchronizuje się automatycznie raz dziennie.',
        'sync_now' => 'Synchronizuj teraz',

        'consent_expired' => 'Zgoda wygasła — połącz ponownie.',
        'unavailable' => 'Enable Banking jest chwilowo niedostępne. Spróbuj ponownie za chwilę.',
        'new_found' => 'Znalezione nowe transakcje: :count.',
        'none' => 'Brak nowych transakcji.',
    ],

    'disconnect' => [
        'heading' => 'Rozłączyć open banking?',
        'body' => 'Usuwa to zapisane dane uwierzytelniające i zgodę Enable Banking. Automatyczna synchronizacja zatrzyma się natychmiast. Transakcje już zaimportowane do Beatrax pozostaną bez zmian.',
        'confirm' => 'Rozłącz',
        'cancel' => 'Zachowaj połączenie',
    ],

    'ics' => [
        'section_label' => 'Import pliku — żadne dane uwierzytelniające nie są przechowywane',
        'heading' => 'Wyciąg z karty kredytowej ICS',
        'step_login' => 'Zaloguj się',
        'step_download' => 'Pobierz wyciąg',
        'pdf_statement' => 'Wyciąg PDF',
        'step_drop' => 'Upuść go poniżej',
        'drop_zone_label' => 'Upuść tutaj plik wyciągu',
        'drop_zone_hint' => 'lub wybierz plik z dysku',
        'browse_aria' => 'Wybierz plik wyciągu ICS',
        'import_button' => 'Zaimportuj wyciąg',
        'validation' => [
            'required' => 'Upuść wyciąg ICS pobrany z Mijn ICS.',
            'max' => 'Ten plik jest za duży. Wyciągi PDF z ICS mają zwykle mniej niż 1 MB.',
            'extensions' => 'To nie jest PDF. Mijn ICS eksportuje wyciągi wyłącznie w formacie PDF.',
        ],
        'could_not_read' => 'Nie udało się odczytać pliku: :filename. Pełny błąd znajdziesz w /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Zanim połączysz się z podmiotem zewnętrznym',
        'body' => 'Włączenie open bankingu wysyła Twoją zgodę na logowanie do banku, a potem dane o transakcjach i saldach, bezpośrednio z tego urządzenia do Enable Banking i Twojego banku. Beatrax nie prowadzi serwera, który widziałby te dane — ale Enable Banking i Twój bank je widzą. To odróżnia tę metodę od wszystkich innych sposobów importu w Beatrax, które nigdy nigdzie nie wysyłają danych.',
        'acknowledge' => 'Rozumiem, że moje dane o transakcjach będą udostępniane Enable Banking i mojemu bankowi.',
        'confirm' => 'Włącz open banking',
        'cancel' => 'Anuluj',
    ],

    'wizard' => [
        'heading' => 'Połącz swój bank',
        'intro' => 'Beatrax korzysta z Twojej własnej aplikacji Enable Banking, więc Twoje dane uwierzytelniające nigdy nie trafiają na wspólny serwer. To jednorazowa konfiguracja dla każdego banku.',

        'step1_title' => 'Wygeneruj lokalną parę kluczy',
        'step1_body' => 'Beatrax generuje parę kluczy RSA na tym urządzeniu. Klucz prywatny nigdy go nie opuszcza.',
        'generate_keypair' => 'Wygeneruj parę kluczy',
        'public_key_label' => 'Klucz publiczny',
        'copy_public_key' => 'Kopiuj klucz publiczny',
        'copied' => 'Skopiowano',
        'redirect_uri_label' => 'Redirect URI',
        'copy_redirect_uri' => 'Kopiuj redirect URI',

        'step2_title' => 'Zarejestruj aplikację w Enable Banking',
        'step2_body' => 'Otwórz portal dla deweloperów Enable Banking, utwórz aplikację i wklej klucz publiczny oraz redirect URI z kroku 1.',
        'open_portal' => 'Otwórz portal Enable Banking ↗',

        'step3_title' => 'Wklej identyfikator aplikacji',
        'application_id_label' => 'Identyfikator aplikacji',
        'step3_help' => 'Jest przechowywany w lokalnym pliku poza bazą danych, z restrykcyjnymi uprawnieniami, i nigdy nie opuszcza tego urządzenia.',

        'step4_title' => 'Wybierz swój bank',
        'via_enable_banking' => 'przez Enable Banking',
        'other_institution' => 'Inna instytucja',
        'institution_id_placeholder' => 'Identyfikator instytucji',

        'step5_title' => 'Dokończ udzielanie zgody w przeglądarce',
        'step5_body' => 'Kliknij poniżej, aby otworzyć ekran logowania i zgody Twojego banku. Zaloguj się, przejdź weryfikację dwuskładnikową, a potem wrócisz tutaj automatycznie, aby dokończyć włączanie Open Bankingu.',

        'cancel' => 'Anuluj',
        'continue' => 'Dalej →',
        'continue_to_bank' => 'Przejdź do: :bank →',
        'your_bank' => 'Twój bank',

        'errors' => [
            'save_keypair_failed' => 'Nie udało się zapisać pary kluczy na dysku — sprawdź uprawnienia katalogu z sekretami i spróbuj ponownie.',
            'generate_failed' => 'Nie udało się wygenerować pary kluczy na tym urządzeniu — sprawdź konfigurację OpenSSL.',
            'export_failed' => 'Nie udało się wyeksportować wygenerowanej pary kluczy.',
            'read_public_failed' => 'Nie udało się odczytać wygenerowanego klucza publicznego.',
            'generate_first' => 'Wygeneruj parę kluczy, zanim przejdziesz dalej.',
            'paste_application_id' => 'Wklej identyfikator aplikacji z portalu Enable Banking, zanim przejdziesz dalej.',
            'save_application_id_failed' => 'Nie udało się zapisać identyfikatora aplikacji na dysku — sprawdź uprawnienia katalogu z sekretami i spróbuj ponownie.',
            'choose_bank' => 'Wybierz bank, zanim przejdziesz dalej.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Połącz ponownie swój bank',
    ],

    'errors' => [
        'wizard_incomplete' => 'Najpierw dokończ kreator konfiguracji Open Banking.',
        'no_bank_chosen' => 'Wybierz bank przed połączeniem.',
        'no_consent_url' => 'Enable Banking nie zwróciło adresu URL zgody.',
        'unparseable_consent_url' => 'Enable Banking zwróciło adres URL zgody, którego nie da się przetworzyć.',
        'non_public_consent_host' => 'Enable Banking zwróciło niepubliczny host zgody.',
        'unsafe_consent_url' => 'Enable Banking zwróciło niebezpieczny adres URL zgody.',
        'no_authorization_code' => 'Wywołanie zwrotne Enable Banking nie zawierało kodu autoryzacji.',
        'no_session_id' => 'Enable Banking nie zwróciło identyfikatora sesji.',
    ],
];
