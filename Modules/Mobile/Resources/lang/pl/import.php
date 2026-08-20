<?php

declare(strict_types=1);

return [
    'page_title' => 'Import z innego urządzenia',

    'heading' => 'Import z innego urządzenia',
    'subtitle' => 'Skonfiguruj ten telefon z własnym kontem i blokadą, a potem sparuj go z drugim urządzeniem, aby pobrać historię.',

    'username' => 'Nazwa użytkownika',
    'password' => 'Hasło',
    'password_help' => 'Co najmniej 12 znaków — nie ma resetu hasła, są tylko kody odzyskiwania.',
    'confirm_password' => 'Potwierdź hasło',
    'pin' => 'PIN blokady aplikacji',
    'pin_help' => '6-10 cyfr — odblokowuje to urządzenie.',
    'confirm_pin' => 'Potwierdź PIN',
    'continue' => 'Dalej',

    'failed_heading' => 'Konfiguracja nie została ukończona',
    'failed_body' => 'Konto zostało utworzone, ale nie udało się dokończyć konfiguracji tego urządzenia. Można bezpiecznie spróbować ponownie.',
    'try_again' => 'Spróbuj ponownie',

    'recovery_heading' => 'Zapisz te kody odzyskiwania',
    'recovery_body' => 'Wydrukuj je albo zapisz w bezpiecznym miejscu. Nie zostaną pokazane ponownie.',
    'already_heading' => 'To urządzenie jest już skonfigurowane',
    'already_body' => 'Twoje konto istnieje na tym urządzeniu. Przejdź do parowania, aby połączyć je z pozostałymi urządzeniami.',
    'recovery_download' => 'Pobierz jako .txt',
    'recovery_copy' => 'Kopiuj kody',
    'recovery_copied' => 'Skopiowano',
    'recovery_copy_failed' => 'Nie udało się skopiować. Zapisz kody.',
    'recovery_saved' => 'Zapisano w pobranych plikach.',
    'recovery_share_title' => 'Kody odzyskiwania Beatrax',
    'recovery_share_message' => 'Przechowuj je w bezpiecznym miejscu.',
    'recovery_save_failed' => 'Nie udało się zapisać pliku. Zapisz kody.',
    'recovery_confirm' => 'Zapisano te kody w bezpiecznym miejscu.',
    'continue_to_pairing' => 'Przejdź do parowania',

    'errors' => [
        'passwords_mismatch' => 'Hasła nie są zgodne.',
        'password_length' => 'Użyj co najmniej 12 znaków.',
        'pin_length' => 'PIN musi mieć co najmniej 6 cyfry.',
        'pins_mismatch' => 'Kody PIN nie są zgodne. Spróbuj ponownie.',
        'session_expired' => 'Sesja wygasła przed zakończeniem konfiguracji. Wpisz ponownie PIN i hasło.',
        'retry_failed' => 'Nadal nie udało się dokończyć konfiguracji tego urządzenia. Spróbuj ponownie.',
        'account_failed' => 'Nie udało się utworzyć konta.',
    ],
];
