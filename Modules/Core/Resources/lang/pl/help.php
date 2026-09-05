<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'O :subject',
        'close' => 'Zamknij',
    ],

    'page_title' => 'Gdzie są moje dane?',
    'intro' => 'Beatrax przechowuje wszystko na tym urządzeniu. Nic nie jest wysyłane na serwer, nic nie synchronizuje się z chmurą, nic nie opuszcza tego urządzenia bez Twojego eksportu.',

    'lives_here' => 'Twoje dane są tutaj',
    'copy' => 'Kopiuj',
    'copied' => 'Skopiowano',

    'location' => [
        'database' => 'Baza danych:',
        'artefacts_imports' => 'Zaimportowane wyciągi:',
        'artefacts_mail' => 'Zeskanowana poczta:',
        'artefacts_drop' => 'Obserwowany katalog:',
        'backups' => 'Kopie zapasowe:',
        'secrets' => 'Dane logowania połączeń:',
        'logs' => 'Dzienniki:',
    ],

    'copy_aria' => [
        'database' => 'Kopiuj ścieżkę bazy danych do schowka',
        'artefacts_imports' => 'Kopiuj ścieżkę zaimportowanych wyciągów do schowka',
        'artefacts_mail' => 'Kopiuj ścieżkę zeskanowanej poczty do schowka',
        'artefacts_drop' => 'Kopiuj ścieżkę obserwowanego katalogu do schowka',
        'backups' => 'Kopiuj ścieżkę kopii zapasowych do schowka',
        'secrets' => 'Kopiuj ścieżkę danych logowania połączeń do schowka',
        'logs' => 'Kopiuj ścieżkę dzienników do schowka',
    ],

    'artefacts_heading' => 'Twoje dokumenty źródłowe nie znajdują się w kopii zapasowej',
    'artefacts_body' => 'Kopia zapasowa zawiera bazę danych i nic poza tym. Wyciągi, które zaimportowałeś, poczta pobrana przez skaner i paragony wrzucone do obserwowanego katalogu zostają tam, gdzie są — w trzech katalogach wymienionych powyżej. Odłożenie kopii zapasowej w bezpieczne miejsce ich nie kopiuje, więc pełne archiwum oznacza zabranie także tych katalogów — albo skorzystanie z opcji Wyeksportuj wszystko poniżej, która pakuje je razem z kopią zapasową.',

    'export_heading' => 'Wyeksportuj wszystko',
    'export_body' => 'Jedno archiwum z zaszyfrowaną kopią Twojej bazy danych i każdym dokumentem źródłowym, jaki przekazałeś Beatraxowi. Rozpakuj je gdziekolwiek, a dokumenty będą w środku takie, jakie zawsze były, w katalogach, z których pochodzą.',
    'export_passphrase_label' => 'Hasło do bazy danych',
    'export_confirm_label' => 'Powtórz hasło',
    'export_passphrase_hint' => 'Baza danych w archiwum jest szyfrowana tym hasłem i bez niego nie da się jej otworzyć, więc wybierz coś, co na pewno zachowasz. Dokumenty źródłowe trafiają tam bez zmian, więc trzymaj archiwum w miejscu, któremu ufasz.',
    'export_cta' => 'Wyeksportuj wszystko jako ZIP',
    'export_working' => 'Trwa tworzenie archiwum…',

    'delete_heading' => 'Usuwanie danych',
    'delete_intro' => 'Twoje dane to pliki na tym urządzeniu, więc usunięcie ich oznacza usunięcie tych plików. Nie ma tu przycisku, który zrobi to za Ciebie, i to celowo: to system plików naprawdę przechowuje Twoją historię, a przycisk, który opróżniłby kilka tabel, zostawiając pliki na miejscu, byłby gorszy niż nic.',
    'delete_uninstall' => 'Odinstalowanie Beatraxa nie usuwa Twoich danych. To celowe — przypadkowe odinstalowanie nie może zniszczyć lat historii — więc wszystko poniżej zostaje na tym urządzeniu, dopóki sam tego nie usuniesz.',
    'delete_list_intro' => 'Aby nie został żaden ślad, usuń każdą z tych rzeczy:',
    'delete_journal_note' => 'Obok bazy danych leżą dwa pliki dziennika, :wal i :shm. Twoje najnowsze zmiany są w nich, dopóki nie zostaną zapisane do bazy, więc usuń wszystkie trzy razem.',
    'no_telemetry' => 'Nie ma żadnej telemetrii, z której trzeba by rezygnować, ani zdalnego konta do zamknięcia.',
];
