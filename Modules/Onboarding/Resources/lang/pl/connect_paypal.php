<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Twoje konto PayPal',
    'h1' => 'Połącz swoje konto PayPal',

    'lede_html' => 'Upuść eksport szczegółów transakcji z PayPal — w holenderskim koncie PayPal nosi on nazwę <em lang="nl">Rapport Transactiegegevens</em>. Raport salda (<span lang="nl">Saldorapport</span>) nie zadziała — potrzebujemy danych o pojedynczych zdarzeniach.',

    'format_group_aria' => 'PayPal eksportuje wyłącznie do CSV',
    'got_it_as' => 'Mam go jako:',
    'badge_only_format' => 'jedyny format',

    'mini' => [
        'login_label' => 'Zaloguj się',
        'custom_label' => 'Wyciągi niestandardowe',
        'range_label' => 'Wybierz zakres',
        'range_sub' => 'Ostatnie 12 miesięcy',
        'download_label' => 'Pobierz jako CSV',
    ],

    'drop_lead' => 'Upuść tutaj plik CSV ze szczegółami transakcji',
    'browse_file' => 'lub wybierz plik z dysku',

    'file_ready' => '· ✓ gotowe',

    'skip' => 'Pomiń ten krok',
    'continue' => 'Dalej →',

    'errors' => [
        'required' => 'Najpierw upuść w tym polu plik CSV PayPal Rapport Transactiegegevens.',
        'max' => 'Ten plik jest za duży. Eksporty PayPal Rapport Transactiegegevens mają zwykle znacznie mniej niż 10 MB.',
        'extensions' => 'Ten plik nie wygląda na plik CSV z PayPal. Pobierz z PayPal raport Rapport Transactiegegevens (nie raport salda Saldorapport) w formacie CSV.',
        'unreadable' => 'Nie udało się odczytać tego pliku. Pełny błąd znajdziesz w /dev/logs.',
    ],
];
