<?php

declare(strict_types=1);

return [
    'heading' => 'Skrzynki',
    'intro' => 'Połącz skrzynki Gmail i Microsoft 365, aby Beatrax mógł skanować je w poszukiwaniu paragonów.',

    'connection_canceled' => 'Połączenie anulowane.',
    'connection_failed' => 'Nie udało się dokończyć połączenia.',

    'backfilling' => 'Uzupełnianie',
    'messages_suffix' => 'wiadomości',

    'connect_heading' => 'Połącz swoją pocztę',
    'connect_body' => 'Importuj paragony z PayPal, ICS Cards, Google Play i od innych sprzedawców, dając Beatrax dostęp tylko do odczytu do jednej lub kilku skrzynek.',
    'connect_gmail' => 'Połącz Gmail',
    'connect_microsoft' => 'Połącz Microsoft 365',
    'readonly_note' => 'Beatrax tylko czyta wiadomości. Nigdy niczego nie wysyła, nie etykietuje, nie przenosi ani nie usuwa w Twojej skrzynce.',

    'month' => '1 miesiąc',
    'months' => ':count mies.',
    'not_scanned_yet' => 'jeszcze nieskanowane',
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

    'retry_seconds' => 'ponowna próba za :ns',
    'retry_minutes' => 'ponowna próba za :nmin',
    'retry_hours' => 'ponowna próba za :nh',

    'reconnect' => 'Połącz ponownie',
    'disconnect' => 'Rozłącz',
    'scan_now' => 'Skanuj teraz',
    'scan_in_progress_title' => 'Skanowanie już trwa',

    'add_another' => 'Dodaj kolejną skrzynkę',
    'gmail_card_body' => 'Połącz konto Gmail, aby Beatrax mógł skanować je w poszukiwaniu paragonów.',
    'microsoft_card_body' => 'Połącz konto Microsoft 365 lub Outlook.com, aby Beatrax mógł skanować je w poszukiwaniu paragonów.',

    'discovered_heading' => 'Wykryci nadawcy',
    'discovered_body' => 'Nadawcy, którzy wyglądają na wysyłających paragony, ale nie ma ich jeszcze na liście znanych nadawców. Dodaj tych, których ma skanować Beatrax; resztę odrzuć.',
    'last_seen' => 'ostatnio widziano',
    'seen_times' => 'Liczba wystąpień: :count',
    'add' => 'Dodaj',
    'add_aria' => 'Dodaj :email',
    'dismiss' => 'Odrzuć',
    'dismiss_aria' => 'Odrzuć :email',

    'toast' => [
        'scan_in_progress' => 'Skanowanie już trwa.',
        'scan_started' => 'Skanowanie rozpoczęte.',
        'sender_added' => 'Nadawca dodany.',
        'sender_dismissed' => 'Nadawca odrzucony.',
    ],
];
