<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'O :subject',
        'close' => 'Zamknij',
    ],

    'page_title' => 'Gdzie są moje dane?',
    'intro' => 'Beatrax przechowuje wszystko na tym urządzeniu. Nie ma żadnego serwera Beatraxa ani konta w chmurze. Samo z siebie wychodzi tylko jedno połączenie — sprawdzenie, czy jest nowa wersja, które możesz wyłączyć. Cała reszta czeka na Ciebie: skrzynka odbiorcza, bank przez Enable Banking, codzienne zapytanie o kursy walut, urządzenia sparowane do synchronizacji, przekaźnik, który skonfigurujesz, i każdy link, w który klikniesz. Każde z nich mówi o tym na ekranie, na którym je włączasz.',

    'lives_here' => 'Twoje dane są tutaj',
    'copy' => 'Kopiuj',
    'copied' => 'Skopiowano',

    'location' => [
        'database' => 'Baza danych:',
        'artifacts_imports' => 'Zaimportowane wyciągi:',
        'artifacts_mail' => 'Zeskanowana poczta:',
        'artifacts_drop' => 'Obserwowany katalog:',
        'backups' => 'Kopie zapasowe:',
        'secrets' => 'Dane logowania połączeń:',
        'logs' => 'Dzienniki:',
    ],

    'copy_aria' => [
        'database' => 'Kopiuj ścieżkę bazy danych do schowka',
        'artifacts_imports' => 'Kopiuj ścieżkę zaimportowanych wyciągów do schowka',
        'artifacts_mail' => 'Kopiuj ścieżkę zeskanowanej poczty do schowka',
        'artifacts_drop' => 'Kopiuj ścieżkę obserwowanego katalogu do schowka',
        'backups' => 'Kopiuj ścieżkę kopii zapasowych do schowka',
        'secrets' => 'Kopiuj ścieżkę danych logowania połączeń do schowka',
        'logs' => 'Kopiuj ścieżkę dzienników do schowka',
    ],

    'artifacts_heading' => 'Twoje dokumenty źródłowe nie znajdują się w kopii zapasowej',
    'artifacts_body' => 'Kopia zapasowa zawiera bazę danych i nic poza tym. Wyciągi, które zaimportowałeś, poczta pobrana przez skaner i paragony wrzucone do obserwowanego katalogu zostają tam, gdzie są — w trzech katalogach wymienionych powyżej. Odłożenie kopii zapasowej w bezpieczne miejsce ich nie kopiuje, więc pełne archiwum oznacza zabranie także tych katalogów — albo skorzystanie z opcji Wyeksportuj wszystko poniżej, która pakuje je razem z kopią zapasową.',

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

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Odcinek czasu, na którym mierzone są twoje budżety, twój pulpit i każda liczba „w tym okresie”. „:label” rozstrzyga, gdzie się zaczyna — ustaw go na dzień po wypłacie, a okres obejmie pieniądze wypłacone na jego pokrycie. Przesunięcie tego dnia przekłada na nowo każdą kwotę już rozdzieloną do kopert, a tam, gdzie dwa stare okresy składają się na jeden nowy, ich kwoty są sumowane; cofnięcie dnia już ich nie rozdziela.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Wszystko, o czym Beatrax wie, że masz, minus wszystko, co jesteś winien: saldo każdego konta, które zaimportowałeś lub podłączyłeś, przy czym salda kart i pożyczek liczą się na twoją niekorzyść. Nie jest pełniejsze niż to, co mu dałeś — konto, którego Beatrax nigdy nie widział, w tej liczbie nie występuje. Salda w innej walucie przeliczane są po kursie pokazanym pod liczbą, a to, czego nie dało się wycenić, jest tam nazwane, zamiast po cichu wypaść.',
];
