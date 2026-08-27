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

        'backup_overdue' => 'Ostatnia zweryfikowana kopia zapasowa ma :hoursh. Uruchom <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan db:backup</code> lub poczekaj na zaplanowane uruchomienie o 03:00.',
        'wal_mode_missing' => 'SQLite nie działa w trybie WAL (obecnie :mode). Równoległe zapisy mogą się zatrzymywać. Uruchom <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>, aby uzyskać wskazówki.',
        'synchronous_misconfigured' => 'Poziom synchronous w SQLite to :level (oczekiwano NORMAL/1). Gwarancje trwałości mogą różnić się od konfiguracji. Uruchom <code class="rounded bg-amber-100 px-1 text-amber-900 dark:bg-amber-900 dark:text-amber-200">php artisan Beatrax:doctor</code>, aby uzyskać wskazówki.',
        'oauth_scrub_set_failed' => 'Maskowanie sekretów OAuth nie działa. Dzienniki i fragmenty audytu mogą zawierać niezamaskowane tokeny do następnego udanego wczytania.',
        'oauth_reauth_required' => 'Sekrety OAuth przeniesiono do magazynu przypisanego do użytkownika. Autoryzuj ponownie Gmail i Microsoft, aby wznowić skanowanie poczty. Stary plik sekretów zmieniono na :file, aby umożliwić wycofanie zmian.',
        'oauth_reconsent' => 'Połącz ponownie swoje konto :provider',
        'reconnect_link' => 'Połącz ponownie →',
    ],
];
