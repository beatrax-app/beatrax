<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Witamy',
        'heading' => 'Witamy w Beatrax',
        'subtitle' => 'Twój lokalny pulpit finansowy jest gotowy. Utwórz pierwsze konto, aby zacząć.',
        'get_started' => 'Rozpocznij',
    ],

    'setup' => [
        'page_title' => 'Przygotowywanie…',
        'pending_heading' => 'Przygotowywanie…',
        'pending_body' => 'Beatrax przygotowuje Twoje dane. To zajmie tylko chwilę.',
        'failed_body' => 'Nie udało się dokończyć konfiguracji. Uruchom Beatrax ponownie; jeśli problem się powtarza, przyczynę znajdziesz w logu.',
        'ready_heading' => 'Gotowe',
        'ready_body' => 'Konfiguracja zakończona. Trwa przechodzenie dalej…',
    ],

    'staging' => [
        'page_title' => 'Odebrano plik',
        'heading_prefix' => 'Odebrano plik: ',
        'button_label' => 'Rozpocznij import',
        'csv_subtitle' => 'Eksport z banku lub z PayPal — rozpocznij import, aby zobaczyć podgląd i potwierdzić.',
        'eml_subtitle' => 'Paragon z poczty — rozpocznij import, aby dołączyć go do transakcji.',
        'empty_heading' => 'Nie udało się otworzyć tego pliku',
        'empty_body' => 'Beatrax nie odczytał otwartego pliku. Spróbuj zaimportować go ze strony Importy.',
        'open_imports' => 'Otwórz Importy',
    ],

    'close' => [
        'title' => 'Zostawić Beatrax uruchomiony?',
        'body' => 'Zamknięcie okna może całkowicie zakończyć Beatrax albo zostawić go działającego po cichu na pasku menu, aby zaplanowane skanowanie poczty trwało dalej.',
        'button_quit' => 'Zakończ Beatrax',
        'button_keep_in_tray' => 'Zostaw w zasobniku',
        'checkbox_remember' => 'Zapamiętaj mój wybór',
    ],
];
