<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Ten telefon nie potrafi zapisać pliku przekazanego przez aplikację, więc zaszyfrowaną kopię tworzysz w aplikacji na komputer. Sparuj to urządzenie, aby oba były zsynchronizowane.',
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
        'restored' => 'Przywrócono. Odśwież aplikację, aby zobaczyć przywrócone dane.',
        'snapshot_saved_prefix' => 'Migawka poprzednich danych została zapisana w',
        'file_label' => 'Zaszyfrowana kopia zapasowa (.enc)',
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
        'choose_file' => 'Wybierz zaszyfrowany plik kopii zapasowej (.enc) do przywrócenia.',
        'upload_failed' => 'Plik nie został przesłany do końca. Może być zbyt duży dla tego urządzenia — przywracanie w aplikacji na komputer przyjmie większą kopię zapasową.',
        'enter_passphrase' => 'Podaj hasło, którym zaszyfrowano kopię zapasową.',
        'unreadable' => 'Nie udało się odczytać wgranego pliku. Spróbuj ponownie.',
    ],
];
