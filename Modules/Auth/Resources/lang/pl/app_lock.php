<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Odblokowanie biometryczne nie jest dostępne na tym urządzeniu.',
    'error_enroll_unprotected' => 'Odblokowanie biometryczne wymaga magazynu kluczy systemu operacyjnego, a ta instalacja go nie ma. Rejestracja pozostawiłaby klucz odblokowujący czytelny obok Twoich danych, więc nie jest tu oferowana.',
    'error_enroll_locked' => 'Odblokuj aplikację przed rejestracją.',
    'error_enroll_failed' => 'Urządzenie odmówiło zapisania klucza. Odblokowanie biometryczne jest niedostępne.',
    'heading' => 'Blokada aplikacji',

    'moved_help' => 'Kod PIN, czas automatycznej blokady i odblokowanie biometryczne znajdują się w ustawieniach synchronizacji tego urządzenia.',
    'moved_cta' => 'Otwórz Synchronizację i urządzenie',

    'toggle_label' => 'Blokuj aplikację kodem PIN',
    'toggle_description' => 'Zastępuje codzienne logowanie kodem PIN. Sesje pozostają aktywne przez 30 dni.',

    'setup_heading' => 'Ustaw PIN, aby włączyć blokadę',
    'new_pin_label' => 'Nowy PIN (6–10 cyfr)',
    'confirm_pin_label' => 'Potwierdź PIN',
    'account_password_label' => 'Hasło do konta',
    'account_password_note' => '(wymagane do utworzenia klucza odzyskiwania)',
    'account_password_placeholder' => 'Twoje hasło do konta',
    'set_pin' => 'Ustaw PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Zmień obecny PIN.',
    'change_pin' => 'Zmień PIN',
    'forgot_pin_link' => 'Nie pamiętasz PIN-u? Zresetuj go hasłem do konta.',

    'biometric_enrolled_description' => 'To urządzenie jest zarejestrowane do odblokowania biometrycznego.',
    'biometric_enroll_description' => 'Zarejestruj to urządzenie, aby odblokowywać je biometrycznie.',
    'remove' => 'Usuń',
    'enroll' => 'Zarejestruj',
    'biometric_unavailable' => 'Odblokowanie biometryczne nie jest dostępne na tym urządzeniu.',

    'deenroll_modal_heading' => 'Usuń odblokowanie biometryczne — potwierdź PIN-em',
    'current_pin_label' => 'Obecny PIN',
    'remove_biometric' => 'Usuń biometrię',
    'keep_biometric' => 'Zachowaj biometrię',

    'auto_lock' => 'Automatyczna blokada po',
    'idle_1' => '1 minucie',
    'idle_5' => '5 minutach',
    'idle_15' => '15 minutach',
    'idle_30' => '30 minutach',

    'disable_modal_heading' => 'Wyłącz blokadę aplikacji — potwierdź PIN-em',
    'disable_lock' => 'Wyłącz blokadę',
    'keep_lock' => 'Zachowaj blokadę aplikacji',

    'forgot_modal_heading' => 'Zresetuj PIN — potwierdź hasłem do konta',
    'forgot_modal_body' => 'Hasło do konta odzyskuje klucz blokady, więc reset PIN-u nigdy nie powoduje utraty danych.',
    'confirm_new_pin_label' => 'Potwierdź nowy PIN',
    'reset_pin' => 'Zresetuj PIN',
    'cancel' => 'Anuluj',

    'change_modal_heading' => 'Zmień PIN — potwierdź obecnym PIN-em',
    'keep_pin' => 'Zachowaj PIN',

    'error_pin_too_short' => 'PIN musi mieć co najmniej 6 cyfry.',
    'error_pin_mismatch' => 'Kody PIN nie są zgodne. Spróbuj ponownie.',
    'error_pin_incorrect' => 'Nieprawidłowy PIN.',
    'error_account_password' => 'Nieprawidłowe hasło do konta.',
    'change_pin_success' => 'Klucz szyfrowania został ponownie zabezpieczony nowym PIN-em.',
    'error_forgot_failed' => 'Reset PIN-u nie powiódł się — klucz odzyskiwania jest niedostępny.',
    'error_enable_first' => 'Najpierw włącz blokadę PIN, zanim zarejestrujesz biometrię.',
];
