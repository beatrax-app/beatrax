<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Wyświetlanie',
        'money' => 'Pieniądze',
        'insights' => 'Analizy i alerty',
        'security' => 'Bezpieczeństwo i urządzenia',
        'data' => 'Importy i dane',
        'app' => 'Aplikacja',
    ],

    'title' => 'Ustawienia',
    'subtitle' => 'Preferencje dotyczące tego, jak Twoje finanse wyglądają w aplikacji.',

    'appearance' => [
        'heading' => 'Wygląd',
        'theme' => 'Motyw',
        'theme_light' => 'Jasny',
        'theme_dark' => 'Ciemny',
        'theme_system' => 'Systemowy',
        'theme_help' => 'Motyw systemowy podąża za jasnym lub ciemnym ustawieniem systemu operacyjnego.',
    ],

    'language' => [
        'apply' => 'Zastosuj',
        'heading' => 'Język',
        'label' => 'Język interfejsu',

        'system' => 'Systemowy',
        'help' => 'Ustawienie systemowe podąża za językiem przeglądarki lub systemu operacyjnego, a domyślnie używa angielskiego.',
    ],

    'currency_display' => [
        'heading' => 'Wyświetlanie waluty',
        'label' => 'Domyślny widok na liście transakcji',
        'eur_only' => 'Tylko EUR',
        'original' => 'Waluta oryginalna',
        'help' => 'Widok nadal można przełączać dla każdej strony z poziomu listy transakcji.',
    ],

    'base_currency' => [
        'heading' => 'Bazowa waluta raportowania',
        'label' => 'Waluta raportowania',
        'help' => 'Wszystkie sumy i zestawienia są przeliczane na tę walutę. Każde konto nadal pokazuje obok swoją oryginalną walutę.',
    ],

    'exchange_rates' => [
        'heading' => 'Kursy walut',
        'fetch_online' => 'Pobieraj aktualne kursy online',
        'online_on' => 'Kursy pobierane codziennie z ECB. Tylko zapytania o pary walutowe — bez danych osobowych.',
        'last_updated' => 'Ostatnia aktualizacja: :date.',
        'online_off' => 'Używane są kursy dołączone do aplikacji. Żadne dane nie opuszczają tego urządzenia.',
        'fetch_aria' => 'Pobierz aktualne kursy walut online',
        'refreshing' => 'Odświeżanie…',
        'next_refresh' => 'Następne automatyczne odświeżenie: codziennie o 09:00',
        'refresh_now' => 'Odśwież teraz',
    ],

    'period' => [
        'heading' => 'Okres',
        'label' => 'Okres zaczyna się w dniu',
        'help' => 'Numer od 1 do 28. Większość osób zostawia 1 (miesiąc kalendarzowy). Wybierz 25, jeśli wypłata wpływa 25. dnia i właśnie wtedy zaczyna się „Twój miesiąc”.',
    ],

    'recurring' => [
        'heading' => 'Wykrywanie płatności cyklicznych',
        'window_label' => 'Okno wykrywania (miesiące)',
        'window_help' => 'Ile miesięcy historii przeszukiwać przy grupowaniu transakcji we wzorce cykliczne.',
        'income_label' => 'Minimalny przychód (centy)',
        'income_help' => 'Przychody poniżej tego progu nie są grupowane automatycznie. Zapisywane w centach — 200000 oznacza 2000,00 €. Ustaw 0, aby wyłączyć próg.',
    ],

    'drift' => [
        'heading' => 'Alerty o odchyleniach',
        'label' => 'Domyślny próg alertu o odchyleniu',
        'help' => 'Alerty pojawiają się, gdy najnowsza kwota cyklicznego obciążenia różni się od poprzedniej o więcej niż ten procent. Ustawienia pojedynczej serii mają pierwszeństwo.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (domyślnie)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Zapisz ustawienia',
    'saved' => 'Zapisano.',

    'anomaly_heading' => 'Wykrywanie anomalii',
    'notifications_heading' => 'Powiadomienia',

    'forecasting' => [
        'heading' => 'Prognozowanie',
        'intro' => 'Beatrax prognozuje Twoje saldo na podstawie bieżącego stanu kont. Dla kont bez sald z wyciągów (PayPal, starsze importy CSV) ustaw tutaj saldo początkowe, aby prognozy zaczynały się od znanego punktu.',
        'no_accounts' => 'Brak kont — zaimportuj wyciąg, aby dodać konto.',
    ],

    'auto_import' => [
        'heading' => 'Automatyczny import',
        'label' => 'Automatyczny import z folderu podrzucania',

        'active_html' => 'Folder podrzucania jest aktywny. Beatrax skanuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> co 5 minut w poszukiwaniu nowych plików.',
        'inactive_html' => 'Po włączeniu Beatrax skanuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> co 5 minut w poszukiwaniu plików <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> i <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> i importuje je tym samym potokiem dopasowania co kreator. Przetworzone pliki trafiają do <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, więc nigdy nie są importowane dwa razy.',
    ],

    'aliases' => [
        'heading' => 'Aliasy',
        'intro' => 'Przejrzyj i edytuj czytelne nazwy przypisane w Beatrax do zagadkowych opisów z wyciągów.',
        'manage' => 'Zarządzaj aliasami →',
    ],

    'tax_heading' => 'Podatki',
    'shared_merchant_heading' => 'Wspólna lista sprzedawców',
    'data_backup_heading' => 'Dane i kopia zapasowa',
    'install_heading' => 'Instalacja',

    'about_updates' => [
        'heading' => 'O aktualizacjach',
        'body' => 'Po zainstalowaniu Beatrax aktualizuje się automatycznie. Po instalacji pierwszej wersji kolejne pojawiają się jako baner w aplikacji — nie trzeba wracać na GitHub. Gdyby przyszła aktualizacja się nie zainstalowała, zawsze można ręcznie pobrać najnowszy instalator ze strony wydań.',
        'open_releases' => 'Otwórz stronę wydań →',
    ],

    'privacy' => [
        'heading' => 'Polityka prywatności',
        'body' => 'Beatrax trzyma twoje finanse na twoich własnych urządzeniach. Polityka wyjaśnia, co to znaczy, co wysyłają opcjonalne funkcje online i jak usunąć swoje dane.',
        'open' => 'Przeczytaj politykę prywatności →',
        'url_hint' => 'Jeśli odnośnik się nie otwiera, wejdź na:',
    ],

    'first_run_tour' => [
        'heading' => 'Przewodnik pierwszego uruchomienia',
        'body' => 'Uruchom kreator konfiguracji ponownie, aby jeszcze raz przejść wprowadzenie.',
        'run_again' => 'Uruchom kreator konfiguracji ponownie',
    ],

    'developer' => [
        'heading' => 'Deweloper',
        'label' => 'Konsola deweloperska w aplikacji',
        'help' => 'Pokazuje konsolę deweloperską pod /dev. Przełącznik Zaawansowane resetuje się przy każdym logowaniu.',
        'aria' => 'Tryb deweloperski',
    ],

    'errors' => [
        'currency_required' => 'Wybierz walutę.',
        'window_months' => 'Wybierz wartość od 2 do 60 miesięcy.',
        'threshold' => 'Wybierz próg spośród 1%, 2%, 5%, 10%, 25% lub 50%.',
        'amount' => 'Podaj kwotę od €0 wzwyż.',
        'period_day' => 'Wybierz dzień od 1 do 28.',
        'currency_view' => 'Wybierz jedną z dostępnych opcji.',
    ],
];
