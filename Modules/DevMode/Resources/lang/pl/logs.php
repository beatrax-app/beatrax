<?php

declare(strict_types=1);

return [
    'heading' => 'Logi',
    'subtitle' => 'Podgląd na żywo dzisiejszego pliku logów Laravela, z podwójnym zabezpieczeniem: redakcją przy zapisie i przy strumieniowaniu.',
    'truncate' => 'Wyczyść',
    'truncate_confirm' => 'Wyczyścić dzisiejszy plik logów? Tej operacji nie można cofnąć.',
    'truncate_title' => 'Opróżnij dzisiejszy plik logów (zachowuje i-węzeł, więc podgląd wznowi się bez zakłóceń)',
    'filters_aria' => 'Filtry logów',
    'severity_aria' => 'Filtr poziomu ważności',
    'channel_placeholder' => 'Filtr kanału…',
    'channel_aria' => 'Filtr kanału',
    'contains_placeholder' => 'Szukaj w widocznych…',
    'contains_aria' => 'Filtr zawierania',
    'pause' => 'Wstrzymaj',
    'resume' => 'Wznów',
    'waiting' => 'Oczekiwanie na wiersze logów…',
    'copy' => 'Kopiuj',
    'copy_title' => 'Kopiuj cały wpis',
    'copy_title_copied' => 'Skopiowano',
    'copy_aria' => 'Kopiuj wpis logu',
    'copy_aria_copied' => 'Skopiowano do schowka',
    'dismiss' => 'Odrzuć',
    'dismiss_title' => 'Odrzuć z widoku (nie zmienia pliku logów)',
    'dismiss_aria' => 'Odrzuć wpis logu z widoku',
    'totals' => [
        'showing' => 'Pokazano',
        'of' => 'z',
        'received' => 'odebranych (limit bufora 10k)',
        'lines_today' => 'wierszy dzisiaj',
        'today' => 'dzisiaj',
        'across' => 'w',
        'daily_files' => 'plikach dziennych',
    ],

    'status' => [
        'poll_interrupted' => 'Odpytywanie logów przerwane. Ponawianie…',
        'paused' => 'Wstrzymano.',
        'copy_failed_prefix' => 'Kopiowanie nie powiodło się: ',
        'clipboard_unavailable' => 'schowek niedostępny',
    ],

    'toast' => [
        'truncated' => 'Log wyczyszczony — zwolniono :size.',
        'nothing' => 'Nie ma czego czyścić.',
    ],
];
