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
        'help' => 'Zmienia słowa widoczne na ekranie oraz sposób zapisu kwot. Ustawienie systemowe podąża za językiem przeglądarki lub systemu operacyjnego, a domyślnie używa angielskiego.',
    ],

    'timezone' => [
        'heading' => 'Strefa czasowa',
        'label' => 'Strefa czasowa tej instalacji',
        'help' => 'Decyduje, na który dzień przypada transakcja i w jakich ramach zapisywane są godziny. Sparowane urządzenia współdzielą to ustawienie, więc oba odczytują ten sam dzień.',
        'this_machine' => 'To urządzenie (:zone)',
    ],

    'sample_data' => [
        'heading' => 'Dane przykładowe',
        'help' => 'Wypełnia to konto wymyśloną księgą — konta, transakcje, budżety, cele i alerty — żeby było na co patrzeć. Dokłada się do tego, co już jest, i nic z tego nie jest danymi prawdziwej osoby.',
        'warning' => 'To zapisuje w twojej własnej księdze i trafia na sparowane urządzenia. Na tym ekranie nie ma cofnięcia.',
        'confirm' => 'Dodaj do tego konta',
        'cancel' => 'Anuluj',
        'load' => 'Wczytaj dane przykładowe',
        'working' => 'Budowanie księgi przykładowej. To chwilę potrwa.',
        'loaded' => 'Dane przykładowe dodane (:count).',
    ],

    'country' => [
        'heading' => 'Kraj',
        'label' => 'Twój kraj',
        'help' => 'Określa, którego kraju przepisy podatkowe, urzędy i opłaty bankowe rozpoznaje aplikacja. Nie zmienia języka ani sposobu zapisu kwot.',
        'choose' => 'Wybierz kraj…',
        'switch_note' => 'Zmiana dodaje nowe kategorie — istniejące znaczniki nigdy się nie zmieniają.',

        'wording_note' => 'Nazwy kategorii podatkowych są w Twoim języku; zeznanie podatkowe w :country używa własnych określeń.',

        'countries' => [
            'at' => 'Austria',
            'be' => 'Belgia',
            'bg' => 'Bułgaria',
            'ca' => 'Kanada',
            'ch' => 'Szwajcaria',
            'cy' => 'Cypr',
            'cz' => 'Czechy',
            'de' => 'Niemcy',
            'dk' => 'Dania',
            'ee' => 'Estonia',
            'es' => 'Hiszpania',
            'fi' => 'Finlandia',
            'fr' => 'Francja',
            'gb' => 'Wielka Brytania',
            'gr' => 'Grecja',
            'hr' => 'Chorwacja',
            'hu' => 'Węgry',
            'ie' => 'Irlandia',
            'is' => 'Islandia',
            'it' => 'Włochy',
            'lt' => 'Litwa',
            'lu' => 'Luksemburg',
            'lv' => 'Łotwa',
            'mt' => 'Malta',
            'nl' => 'Holandia',
            'no' => 'Norwegia',
            'pl' => 'Polska',
            'pt' => 'Portugalia',
            'ro' => 'Rumunia',
            'se' => 'Szwecja',
            'si' => 'Słowenia',
            'sk' => 'Słowacja',
            'us' => 'Stany Zjednoczone',
        ],
    ],

    'currency_display' => [
        'heading' => 'Wyświetlanie kwoty',
        'label' => 'Domyślny widok kwot',
        'eur_only' => 'Kwota rozliczona',
        'original' => 'Kwota pierwotna',
        'help' => 'Dotyczy listy transakcji i sum na pulpicie. Widok nadal można przełączać dla każdej strony, ale tylko z poziomu listy transakcji.',
    ],

    'base_currency' => [
        'heading' => 'Bazowa waluta raportowania',
        'label' => 'Waluta raportowania',
        'help' => 'Wszystkie sumy i zestawienia są przeliczane na tę walutę. Każde konto nadal pokazuje obok swoją oryginalną walutę.',
    ],

    'exchange_rates' => [
        'heading' => 'Kursy walut',
        'fetch_online' => 'Pobieraj aktualne kursy online',
        'online_on' => 'Kursy pobierane codziennie z ECB lub z Frankfurtera, gdy ECB jest niedostępny. Tylko zapytania o pary walutowe — bez danych osobowych.',
        'last_updated' => 'Ostatnia aktualizacja: :date.',
        'online_off' => 'Nadal używane są już zapisane kursy, a dołączona migawka służy jako zapas. Żadne dane nie opuszczają tego urządzenia.',
        'fetch_aria' => 'Pobierz aktualne kursy walut online',
        'refreshing' => 'Odświeżanie…',
        'next_refresh' => 'Automatyczne odświeżanie: raz dziennie',
        'refresh_gave_up' => 'Nie udało się odświeżyć kursów. Nadal używane są kursy zapisane na tym urządzeniu.',
        'refresh_now' => 'Odśwież teraz',
    ],

    'period' => [
        'heading' => 'Okres',
        'label' => 'Okres zaczyna się w dniu',
        'help' => 'Numer od 1 do 28. Większość osób zostawia 1 (miesiąc kalendarzowy). Wybierz 25, jeśli wypłata wpływa 25. dnia i właśnie wtedy zaczyna się „Twój miesiąc”.',

        'move_confirm' => 'Jeśli okres zaczyna się :day. dnia, wszystkie kwoty w kopertach zostaną przeniesione i zsumowane tam, gdzie dwa miesiące zlewają się w jeden. Cofnięcie dnia już ich nie rozdzieli.',
        'move_cancel' => 'Anuluj',
        'move_apply' => 'Zastosuj',
    ],

    'recurring' => [
        'heading' => 'Wykrywanie płatności cyklicznych',
        'window_label' => 'Okno wykrywania (miesiące)',
        'window_help' => 'Ile miesięcy historii przeszukiwać przy grupowaniu transakcji we wzorce cykliczne.',
        'income_label' => 'Minimalny przychód (najmniejsze jednostki)',
        'income_help' => 'Przychody poniżej tego progu nie są grupowane automatycznie. Zapisywane w najmniejszych jednostkach — :minor oznacza :example. Ustaw 0, aby wyłączyć próg.',
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
        'active_phone_html' => 'Folder podrzucania jest aktywny. Beatrax skanuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> w tle w poszukiwaniu nowych plików. To telefon decyduje, kiedy uruchomi się skanowanie w tle — mogą to być minuty albo godziny.',
        'inactive_phone_html' => 'Po włączeniu Beatrax skanuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> w tle w poszukiwaniu plików <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> i <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> i importuje je tym samym potokiem dopasowania co kreator. To telefon decyduje, kiedy uruchomi się skanowanie w tle — mogą to być minuty albo godziny. Przetworzone pliki trafiają do <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, więc nigdy nie są importowane dwa razy.',
    ],

    'aliases' => [
        'heading' => 'Aliasy',
        'intro' => 'Przejrzyj i edytuj czytelne nazwy przypisane w Beatrax do zagadkowych opisów z wyciągów.',
        'manage' => 'Zarządzaj aliasami →',
    ],

    'tax_heading' => 'Podatki',
    'data_backup_heading' => 'Dane i kopia zapasowa',

    'about_updates' => [
        'heading' => 'O aktualizacjach',
        'body' => 'Po zainstalowaniu Beatrax aktualizuje się automatycznie. Po instalacji pierwszej wersji kolejne pojawiają się jako baner w aplikacji — nie trzeba wracać na GitHub. Gdyby przyszła aktualizacja się nie zainstalowała, zawsze można ręcznie pobrać najnowszy instalator ze strony wydań.',
        'body_phone' => 'Tutaj Beatrax nie aktualizuje się sam. Nowe wersje aplikacji na telefon przychodzą przez App Store lub Google Play, tak jak pozostałe Twoje aplikacje.',
        'check_label' => 'Automatycznie sprawdzaj aktualizacje',
        'check_on' => 'Beatrax pyta kanał wydań, czy istnieje nowsza podpisana wersja. Nic nie jest pobierane, dopóki sam nie wybierzesz instalacji.',
        'check_off' => 'Aktualizacje nie są sprawdzane i nic nie opuszcza tego urządzenia. Nowe wersje znajdziesz, otwierając stronę wydań samodzielnie.',
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
        'period_move_failed' => 'Nie udało się przesunąć miesiąca budżetowego, więc został tam, gdzie był.',
        'currency_required' => 'Wybierz walutę.',
        'window_months' => 'Wybierz wartość od 2 do 60 miesięcy.',
        'threshold' => 'Wybierz próg spośród 1%, 2%, 5%, 10%, 25% lub 50%.',
        'amount' => 'Podaj kwotę od :zero wzwyż.',
        'period_day' => 'Wybierz dzień od 1 do 28.',
        'currency_view' => 'Wybierz jedną z dostępnych opcji.',
        'timezone' => 'Wybierz strefę czasową z listy.',
    ],
];
