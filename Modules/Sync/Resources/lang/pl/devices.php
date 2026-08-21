<?php

declare(strict_types=1);

return [
    'heading' => 'Urządzenia i synchronizacja',

    'enable_sync' => 'Włącz synchronizację',
    'enable_sync_help' => 'Bezpiecznie udostępniaj swoje dane zaufanym urządzeniom. Wymaga blokady aplikacji.',

    'app_lock_notice' => 'Najpierw ustaw blokadę aplikacji, aby włączyć synchronizację.',
    'go_to_app_lock' => 'Przejdź do Blokady aplikacji',

    'encrypted_at_rest' => 'Dane szyfrowane w spoczynku',
    'encrypted_at_rest_scope' => 'Notatki, opisy transakcji oraz nazwy i numery IBAN odbiorców są szyfrowane hasłem blokady aplikacji. Kwoty, daty oraz nazwa i IBAN Twojego własnego konta nie są, a niektóre nazwy sprzedawców nadal występują jawnie w innych miejscach pliku bazy danych.',
    'on' => 'Wł.',
    'securing' => 'Zabezpieczanie Twoich danych…',
    'do_not_close' => 'Nie zamykaj tego okna.',
    'encryption_progress_aria' => 'Postęp szyfrowania',
    'not_encrypted_offer' => 'Twoje dane nie są szyfrowane w spoczynku. Skonfiguruj szyfrowanie, aby je chronić na wypadek zgubienia lub kradzieży tego urządzenia.',
    'enable_encryption' => 'Włącz szyfrowanie',

    'your_devices' => 'Twoje urządzenia',

    'moved_help' => 'Parowanie, nazwy urządzeń i szyfrowanie znajdziesz teraz przy statusie synchronizacji.',
    'moved_cta' => 'Otwórz Synchronizację i urządzenie',
    'device_name' => 'Nazwa urządzenia',
    'save' => 'Zapisz',
    'peer_default_name' => 'Sparowane urządzenie',
    'rename_device' => 'Zmień nazwę urządzenia',
    'this_device' => 'To urządzenie',
    'removed' => 'Usunięte',
    'confirmed' => 'Potwierdzone',
    'awaiting_confirmation' => 'Oczekuje na potwierdzenie',
    'safety_number_words' => 'Słowa numeru bezpieczeństwa:',
    'paired' => 'Sparowane',
    'remove_aria' => 'Usuń: :name',
    'remove' => 'Usuń',
    'pair_new_device' => 'Sparuj nowe urządzenie',

    'relay_endpoint' => 'Adres przekaźnika',
    'relay_endpoint_help' => 'Opcjonalne. Gdy jest ustawiony, urządzenia offline synchronizują się przez ten przekaźnik. Zostaw puste, aby korzystać wyłącznie z połączenia LAN&#8209;bezpośredniego.',
    'relay_endpoint_aria' => 'Adres URL przekaźnika',
    'relay_insecure_warning' => 'Ten adres przekaźnika używa zwykłego HTTP. Przekaźnik nigdy nie odszyfrowuje Twoich danych, ale niezabezpieczone połączenie ujawnia obserwatorom sieci rozmiary i czasy zaszyfrowanych przesyłek. Dla najlepszej prywatności użyj adresu <strong>https://</strong>.',

    'enable_at_rest' => 'Włącz szyfrowanie w spoczynku',
    'enable_at_rest_body' => 'Twoje dane zostaną zaszyfrowane hasłem blokady aplikacji. Kopia zapasowa sprzed migracji powstanie automatycznie.',
    'no_recovery_warning' => 'Jeśli zgubisz hasło blokady aplikacji i nie masz kopii zapasowej ani innego zaufanego urządzenia, danych nie da się odzyskać.',
    'recover_help' => 'Aby odzyskać dostęp, sparuj to urządzenie ponownie z innego zaufanego urządzenia albo użyj własnej, niezależnej zaszyfrowanej kopii zapasowej.',
    'amounts_plaintext' => 'Kwoty nie są szyfrowane w spoczynku — salda i sumy pozostają czytelne, dzięki czemu miesięczne podsumowania nadal się zgadzają.',
    'search_plaintext' => 'Indeks wyszukiwania przechowuje jawną kopię nazw sprzedawców i opisów, aby wyszukiwanie pełnotekstowe działało dalej.',
    'keep_unencrypted' => 'Zostaw dane niezaszyfrowane',
    'encryption_enabled' => 'Szyfrowanie włączone',
    'encryption_enabled_body' => 'Twoje dane są teraz szyfrowane w spoczynku.',
    'done_encryption_enabled' => 'Gotowe — szyfrowanie włączone',
    'encryption_failed' => 'Konfiguracja szyfrowania nie powiodła się',
    'encryption_failed_body' => 'Twoje dane nie zostały zmienione. Kopia zapasowa została zachowana.',
    'close_no_changes' => 'Zamknij — bez zmian',

    'remove_this_device' => 'Usuń to urządzenie',
    'removing' => 'Usuwanie:',
    'remove_rotates_key' => 'Usunięcie tego urządzenia powoduje rotację klucza szyfrowania, więc nie otrzyma ono żadnych przyszłych aktualizacji.',
    'remove_cannot_erase' => 'Nie usunie to danych, które już są na tamtym urządzeniu. Jeśli urządzenie zostało zgubione lub skradzione, potraktuj wszystkie znajdujące się na nim dane jako ujawnione.',
    'remove_device' => 'Usuń urządzenie',
    'keep_device' => 'Zachowaj urządzenie',
    'rotating_key' => 'Rotacja klucza szyfrowania…',

    'flash' => [
        'app_lock_first' => 'Najpierw ustaw blokadę aplikacji, aby włączyć synchronizację.',
        'enable_failed' => 'Nie udało się włączyć synchronizacji. Upewnij się, że blokada aplikacji jest aktywna, i spróbuj ponownie.',
        'cannot_remove_self' => 'Nie możesz usunąć tego urządzenia — właśnie z niego korzystasz.',
        'remove_failed' => 'Nie udało się usunąć urządzenia. Spróbuj ponownie.',
        'app_lock_first_settings' => 'Najpierw ustaw blokadę aplikacji, aby zmienić ustawienia synchronizacji.',
        'relay_cleared' => 'Adres przekaźnika wyczyszczony.',
        'relay_saved' => 'Adres przekaźnika zapisany.',
        'relay_save_failed' => 'Nie udało się zapisać adresu przekaźnika: :message',
    ],
];
