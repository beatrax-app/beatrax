<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alerty systemowe',

    'actions' => [
        'install_next_launch' => 'Zainstaluj przy następnym uruchomieniu',
        'install_next_launch_aria' => 'Zainstaluj przy następnym uruchomieniu — oznacza alert systemowy #:id jako rozwiązany',
        'skip_version' => 'Pomiń tę wersję',
        'release_notes' => 'Informacje o wydaniu →',
        'update_now' => 'Zaktualizuj teraz',
        'update_now_aria' => 'Zaktualizuj teraz — oznacza alert systemowy #:id jako rozwiązany',
        'remind_later' => 'Przypomnij później',
        'mark_resolved' => 'Oznacz jako rozwiązany',
        'mark_resolved_aria' => 'Oznacz jako rozwiązany — alert systemowy #:id',
    ],

    'messages' => [
        'update_available' => 'Dostępna aktualizacja — Beatrax :version jest gotowy. Zainstaluje się przy następnym uruchomieniu.',
        'update_stale' => 'Używasz wersji :current — wersja :latest jest dostępna od 30 dni. Zaktualizuj teraz.',
        'update_critical' => 'Dostępna krytyczna aktualizacja — wersja :version naprawia: :summary. Zainstaluj jak najszybciej.',
        'backup_corrupt_with_path' => 'Kopia zapasowa zapisana :timestamp nie przeszła kontroli integralności. Sprawdź :path. Rozwiąż to, zanim zaczniesz polegać na kopiach zapasowych.',
        'backup_corrupt_no_path' => 'Kopia zapasowa rozpoczęta :timestamp została przerwana, zanim powstał jakikolwiek plik — źródłowa baza danych nie przeszła kontroli integralności. Rozwiąż to, zanim zaczniesz polegać na kopiach zapasowych.',
        'backup_write_failed' => 'Kopia zapasowa rozpoczęta o :timestamp nie została ukończona — baza danych przeszła kontrole, ale nie udało się zapisać plików kopii. Sprawdź wolne miejsce i uprawnienia folderu kopii zapasowych.',
        'backup_restore_failed' => 'Przywracanie rozpoczęte o :timestamp nie zostało ukończone. Twoje poprzednie dane zapisano wcześniej w :snapshot.',

        'backup_overdue' => 'Ostatnia zweryfikowana kopia zapasowa ma :hoursh. Beatrax robi tę kopię sam, raz dziennie, gdy aplikacja jest otwarta — nie ma nic do uruchomienia ręcznie. Jeśli nadal jest tak stara, aplikacja nie była otwarta, gdy przypadało codzienne uruchomienie.',
        'backup_none_found' => 'W folderze kopii zapasowych nie znaleziono żadnej zweryfikowanej kopii. Beatrax robi tę kopię sam, raz dziennie, gdy aplikacja jest otwarta — nie ma nic do uruchomienia ręcznie.',
        'wal_mode_missing' => 'SQLite nie działa w trybie WAL (obecnie :mode). Równoległe zapisy mogą się zatrzymywać. Uruchom <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code>, aby uzyskać wskazówki.',
        'synchronous_misconfigured' => 'Poziom synchronous w SQLite to :level (oczekiwano NORMAL/1). Gwarancje trwałości mogą różnić się od konfiguracji. Uruchom <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan beatrax:doctor</code>, aby uzyskać wskazówki.',
        'oauth_scrub_set_failed' => 'Maskowanie sekretów OAuth nie działa. Dzienniki i fragmenty audytu mogą zawierać niezamaskowane tokeny do następnego udanego wczytania.',
        'oauth_reauth_required' => 'Sekrety OAuth przeniesiono do magazynu przypisanego do użytkownika. Autoryzuj ponownie Gmail i Microsoft, aby wznowić skanowanie poczty. Stary plik sekretów zmieniono na :file, aby umożliwić wycofanie zmian.',
        'oauth_reconsent' => 'Połącz ponownie swoje konto :provider',
        'auth_recovery_code_consumed' => 'Kod odzyskiwania użyty przez :username.',
        'auth_recovery_code_failed' => 'Nieudana próba użycia kodu odzyskiwania dla :username.',
        'auth_lock_hard_cap_reached' => 'Wylogowano po zbyt wielu nieudanych próbach PIN.',
        'open_banking_reconsent' => 'Połącz ponownie swój bank',
        'open_banking_nothing_imported' => 'Twój bank przysłał transakcje, ale Beatrax nie zapisał żadnej z nich, więc nic nie trafiło do twojej ewidencji. Otwórz ustawienia Open banking, aby zobaczyć dlaczego.',
        'auth_lock_corrupted_key' => 'Twój PIN nie może odblokować aplikacji na tym urządzeniu: zapisany klucz jest nieczytelny. Zaloguj się hasłem do konta, aby ustawić nowy PIN.',
        'sync_gdk_rewrap_failed' => 'Ponowne opakowanie pęku kluczy GDK nie powiodło się po zmianie hasła blokady aplikacji — zaszyfrowane dane mogą być nie do odzyskania, dopóki pęk nie zostanie opakowany ponownie.',
        'worker_crashed' => 'Przetwarzanie w tle w Beatraxie nieoczekiwanie się zatrzymało. Importy i skanowanie poczty są wstrzymane. Otwórz aplikację ponownie, aby je uruchomić.',
        'auth_lock_key_material_stranded' => 'Szyfrowanie w spoczynku jest aktywne dla tego konta, ale żadne opakowanie blokady aplikacji nie przechowuje już klucza danych, więc każda zaszyfrowana notatka, opis i dane kontrahenta odczytują się jako puste. Jedyną drogą powrotu jest sparowanie z urządzeniem, które wciąż ma klucz.',
        'auth_lock_recovery_wrap_stale' => 'Hasło konta zmieniono bez ponownego opakowania odzyskiwania blokady aplikacji, więc to hasło nie otwiera już blokady. PIN nadal ją otwiera. Ponownie powiąż hasło konta w ustawieniach blokady, póki PIN jest jeszcze znany — inaczej za zapomnianym PIN-em nie zostanie nic.',
        'reconnect_link' => 'Połącz ponownie →',
    ],
];
