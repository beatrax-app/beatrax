<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ta aplikacja nie może przekazać pliku twojemu urządzeniu, więc zaszyfrowaną kopię tworzysz w aplikacji na komputer. Sparuj to urządzenie, aby oba były zsynchronizowane.',
        'unavailable' => 'Zaszyfrowane kopie zapasowe są dostępne w wersji desktopowej (SQLite). Przy bazie danych na serwerze użyj własnych narzędzi kopii zapasowych tej bazy.',
        'intro' => 'Pobierz kopię całej swojej bazy danych zaszyfrowaną hasłem — bezpieczną do trzymania na dysku zewnętrznym lub w chmurze, bo bez hasła jest nieczytelna (odporne na komputery kwantowe XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Hasło',
        'confirm_passphrase' => 'Potwierdź hasło',
        'keep_safe' => 'Przechowuj hasło w bezpiecznym miejscu — bez niego nie da się odzyskać kopii zapasowej.',
        'submit' => 'Pobierz zaszyfrowaną kopię zapasową',
        'preparing' => 'Przygotowywanie…',
    ],

    'restore' => [
        'heading' => 'Przywróć z kopii zapasowej',

        'intro_html' => 'Zastąp obecną bazę danych zaszyfrowaną kopią zapasową. Plik jest odszyfrowywany i sprawdzany, zanim cokolwiek się zmieni, a migawka obecnych danych zapisywana jest jeszcze przed przywracaniem — ale to nadal <strong class="text-slate-700 dark:text-slate-200">nadpisuje wszystko</strong>, więc jest dodatkowo zabezpieczone. Zostaniesz wylogowany, bo Twoje logowanie też jest w bazie danych.',
        'restored' => 'Kopia zapasowa została przywrócona. Zaloguj się nazwą użytkownika i hasłem obowiązującymi w chwili jej utworzenia.',
        'snapshot_saved_prefix' => 'Migawka poprzednich danych została zapisana w',
        'file_label' => 'Plik kopii zapasowej (.enc) lub archiwum eksportu (.zip)',
        'uploading' => 'Wgrywanie…',
        'passphrase' => 'Hasło',
        'confirm_prefix' => 'Wpisz',
        'confirm_suffix' => 'aby potwierdzić',
        'submit' => 'Przywróć (nadpisuje obecne dane)',
        'restoring' => 'Przywracanie…',
    ],

    'errors' => [
        'passphrase_min' => 'Użyj hasła o długości co najmniej :min znak.|Użyj hasła o długości co najmniej :min znaki.|Użyj hasła o długości co najmniej :min znaków.',
        'passphrase_mismatch' => 'Oba hasła nie są takie same.',
        'download_sqlite_only' => 'Zaszyfrowane pobieranie jest dostępne tylko w wersji SQLite.',
        'create_failed' => 'Nie udało się utworzyć kopii zapasowej: :message',
        'confirm_phrase' => 'Wpisz :phrase, aby potwierdzić — to zastąpi obecne dane.',
        'choose_file' => 'Wybierz, skąd przywrócić dane: z pliku .enc kopii zapasowej albo z archiwum .zip zapisanego przez eksport jednym kliknięciem.',
        'upload_failed' => 'Plik nie został przesłany do końca. Może być zbyt duży dla tego urządzenia — przywracanie w aplikacji na komputer przyjmie większą kopię zapasową.',
        'enter_passphrase' => 'Podaj hasło, którym zaszyfrowano kopię zapasową.',
        'unreadable' => 'Nie udało się odczytać wgranego pliku. Spróbuj ponownie.',
        'restore_wrong_passphrase' => 'To hasło nie otworzyło tej kopii zapasowej i nic nie zostało zmienione. Wpisz je ponownie i spróbuj jeszcze raz. Jeśli na pewno jest właściwe, plik został zmieniony po utworzeniu — przywróć wtedy z innej kopii.',
        'restore_not_a_backup' => 'Ten plik nie zawiera kopii zapasowej Beatrax, więc nie ma z czego przywracać i nic nie zostało zmienione. Wybierz plik .enc zapisany przez aplikację podczas tworzenia kopii albo archiwum .zip zapisane przez eksport jednym kliknięciem.',
        'restore_contents_unreadable' => 'Kopia zapasowa się otworzyła, ale baza danych w środku jest uszkodzona, więc nie została przywrócona i nic nie zostało zmienione. Przywróć ze starszej kopii.',
        'restore_could_not_read' => 'Nie udało się odczytać pliku kopii zapasowej, więc przywracanie nie zostało wykonane i nic nie zostało zmienione. Sprawdź, czy na urządzeniu jest wolne miejsce, i spróbuj ponownie.',
        'restore_not_supported' => 'Przywracanie działa w wersji trzymającej dane w jednym pliku, a ta nią nie jest, więc nic nie zostało zmienione. Przy bazie serwerowej użyj narzędzi przywracania tej bazy.',
        'restore_failed' => 'Przywracanie nie zostało wykonane i nic nie zostało zmienione. Spróbuj ponownie — jeśli nadal się nie udaje, dziennik aplikacji zapisuje, co je zatrzymało.',
    ],
];
