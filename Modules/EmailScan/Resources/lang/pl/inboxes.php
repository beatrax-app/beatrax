<?php

declare(strict_types=1);

return [
    'heading' => 'Skrzynki',
    'intro' => 'Połącz skrzynki Gmail i Microsoft 365, aby Beatrax mógł skanować je w poszukiwaniu paragonów.',
    'intro_phone' => 'Skanowanie skrzynek odbywa się w aplikacji na komputer, nie na tym telefonie.',

    'phone_heading' => 'Ten telefon nie skanuje skrzynek',
    'phone_body' => 'Połącz Gmail lub Microsoft 365 w aplikacji na komputer — znalezione tam paragony trafiają tutaj przez synchronizację.',
    'connection_canceled' => 'Połączenie anulowane.',
    'connection_failed' => 'Nie udało się dokończyć połączenia.',

    'backfilling' => 'Uzupełnianie',
    'backfill_progress' => ':fetched / ~:count wiadomość|:fetched / ~:count wiadomości|:fetched / ~:count wiadomości',

    'connect_heading' => 'Połącz swoją pocztę',
    'connect_body' => 'Importuj paragony z PayPal, ICS Cards, Google Play i od innych sprzedawców, dając Beatrax dostęp tylko do odczytu do jednej lub kilku skrzynek.',
    'connect_body_phone' => 'Paragony z PayPal, ICS Cards, Google Play i od innych sprzedawców importuje aplikacja na komputer, ze skrzynek, do których dajesz jej dostęp tylko do odczytu. Ten telefon pokazuje, co ten import znajdzie.',
    'connect_gmail' => 'Połącz Gmail',
    'connect_microsoft' => 'Połącz Microsoft 365',
    'readonly_note' => 'Beatrax tylko czyta wiadomości. Nigdy niczego nie wysyła, nie etykietuje, nie przenosi ani nie usuwa w Twojej skrzynce.',

    'months' => ':count mies.|:count mies.|:count mies.',
    'not_scanned_yet' => 'jeszcze nieskanowane',
    'not_scanned_yet_phone' => 'nieskanowane na tym telefonie',
    'last_scanned' => 'ostatnio skanowane',
    'window_prefix' => 'Okres:',
    'edit' => 'Edytuj',

    'badge' => [
        'idle' => 'Bezczynne',
        'backfilling' => 'Uzupełnianie',
        'scanning' => 'Skanowanie',
        'rate_limited' => 'Limit zapytań',
        'needs_reauth' => 'Wymaga ponownej autoryzacji',
        'error' => 'Błąd',
    ],

    'error_detail' => 'Ostatnie skanowanie nie zostało ukończone. Spróbuj Skanuj teraz lub połącz tę skrzynkę ponownie.',
    'oauth_state_mismatch' => 'Ten link połączenia wygasł lub został już użyty. Rozpocznij łączenie od nowa.',
    'oauth_client_missing' => 'Jednorazowa konfiguracja tego dostawcy poczty nie została ukończona na tym urządzeniu, więc nie ma jeszcze czym się połączyć. Naciśnij ponownie Połącz, aby ją dokończyć.',
    'oauth_no_code' => 'Dostawca poczty odesłał Cię bez kodu, którego Beatrax potrzebuje do zakończenia, więc żadna skrzynka nie została połączona. Rozpocznij łączenie od nowa.',
    'oauth_grant_refused' => 'Dostawca poczty odrzucił uprawnienie przyznane Beatrax — wygasło albo zostało cofnięte. Rozpocznij łączenie od nowa i przyznaj je.',
    'oauth_exchange_failed' => 'Dostawca poczty nie dokończył łączenia, więc żadna skrzynka nie została dodana. Spróbuj ponownie za kilka minut.',
    'oauth_not_saved' => 'Nie udało się zapisać połączenia na tym urządzeniu, więc żadna skrzynka nie została dodana. Spróbuj ponownie — jeśli nadal się nie udaje, dziennik aplikacji zapisuje, co je zatrzymało.',
    'oauth_no_offline_access_google' => 'Google nie przyznało trwałego uprawnienia, którego potrzebuje Beatrax, więc ta skrzynka przestałaby być skanowana w ciągu godziny. Opublikuj swój ekran zgody OAuth w wersji produkcyjnej i połącz ponownie.',
    'oauth_no_offline_access' => 'Dostawca poczty nie przyznał trwałego uprawnienia, którego potrzebuje Beatrax, więc ta skrzynka przestałaby być skanowana w ciągu godziny. Połącz ponownie i zezwól na dostęp offline, gdy pojawi się pytanie.',
    'oauth_no_offline_access_google_phone' => 'Google nie przyznało trwałego uprawnienia, którego potrzebuje Beatrax, więc nie połączono żadnej skrzynki. Opublikuj swój ekran zgody OAuth w wersji produkcyjnej i połącz ponownie — samo skanowanie działa w aplikacji na komputer.',
    'oauth_no_offline_access_phone' => 'Dostawca poczty nie przyznał trwałego uprawnienia, którego potrzebuje Beatrax, więc nie połączono żadnej skrzynki. Połącz ponownie i zezwól na dostęp offline, gdy pojawi się pytanie — samo skanowanie działa w aplikacji na komputer.',

    'retry_seconds' => 'ponowna próba za :ns',
    'retry_minutes' => 'ponowna próba za :nmin',
    'retry_hours' => 'ponowna próba za :nh',

    'reconnect' => 'Połącz ponownie',
    'disconnect' => 'Rozłącz',
    'disconnect_confirm' => 'Rozłączyć :email? Usuwa to zapisane dane logowania tej skrzynki, jej historię skanowania oraz nadawców, których dodano lub odrzucono. Paragony już wprowadzone do Beatrax pozostaną bez zmian. Ponowne połączenie zaczyna skanowanie od nowa.',
    'scan_now' => 'Skanuj teraz',
    'scan_in_progress_title' => 'Skanowanie już trwa',

    'add_another' => 'Dodaj kolejną skrzynkę',
    'gmail_card_body' => 'Połącz konto Gmail, aby Beatrax mógł skanować je w poszukiwaniu paragonów.',
    'microsoft_card_body' => 'Połącz konto Microsoft 365 lub Outlook.com, aby Beatrax mógł skanować je w poszukiwaniu paragonów.',
    'gmail_card_body_phone' => 'Gmaila skanuje aplikacja na komputer. Konto połączone tutaj nigdy nie jest skanowane samo z siebie.',
    'microsoft_card_body_phone' => 'Microsoft 365 i Outlook.com skanuje aplikacja na komputer. Konto połączone tutaj nigdy nie jest skanowane samo z siebie.',

    'discovered_heading' => 'Wykryci nadawcy',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (wyciągi)',
    ],
    'discovered_body' => 'Nadawcy, którzy wyglądają na wysyłających paragony, ale nie ma ich jeszcze na liście znanych nadawców. Dodaj tych, których ma skanować Beatrax; resztę odrzuć.',
    'last_seen' => 'ostatnio widziano',
    'seen_times' => 'Liczba wystąpień: :count|Liczba wystąpień: :count|Liczba wystąpień: :count',
    'add' => 'Dodaj',
    'add_aria' => 'Dodaj :email',
    'dismiss' => 'Odrzuć',
    'dismiss_aria' => 'Odrzuć :email',

    'toast' => [
        'reconnect_first' => 'Połącz tę skrzynkę ponownie przed skanowaniem.',
        'scan_in_progress' => 'Skanowanie już trwa.',
        'scan_started' => 'Skanowanie rozpoczęte.',
        'sender_added' => 'Nadawca dodany.',
        'sender_dismissed' => 'Nadawca odrzucony.',
    ],
];
