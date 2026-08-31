<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Twoja karta kredytowa',
    'h1' => 'Pobierz miesięczne wyciągi w PDF',
    'lede' => 'Upuść wszystkie miesięczne wyciągi w PDF — połączymy je w jeden podgląd.',

    'format_group_aria' => 'ICS eksportuje wyłącznie PDF',
    'issuer_note' => 'ICS to na razie jedyny wydawca kart, którego potrafimy odczytać, i tylko jego wyciąg po niderlandzku. Jeśli masz kartę innego wydawcy, pomiń ten krok.',
    'got_it_as' => 'Mam je jako:',
    'badge_only_format' => 'jedyny format',

    'mini' => [
        'login_label' => 'Zaloguj się',
        'statements_label' => 'Otwórz wyciągi',
        'months_label' => 'Wybierz miesiące',
        'months_sub' => 'Jeden PDF na miesiąc',
        'download_label' => 'Pobierz',
    ],

    'drop_lead' => 'Upuść tutaj pliki PDF z ICS',
    'browse_files' => 'lub wybierz pliki z dysku',
    'queue_aria' => 'Wyciągi PDF w kolejce',

    'skip' => 'Pomiń ten krok',
    'continue' => 'Dalej →',

    'errors' => [
        'required' => 'Upuść miesięczne wyciągi PDF pobrane z Mijn ICS.',
        'min' => 'Zanim przejdziesz dalej, upuść co najmniej jeden wyciąg PDF z ICS.',
        'each_required' => 'Upuść miesięczny wyciąg PDF pobrany z Mijn ICS.',
        'each_max' => 'Jeden z Twoich plików jest za duży. Wyciągi PDF z ICS mają zwykle mniej niż 1 MB.',
        'each_extensions' => 'Jeden z Twoich plików nie jest plikiem PDF. Mijn ICS eksportuje wyłącznie PDF — spróbuj z najnowszym miesięcznym wyciągiem.',
        'file_unreadable' => 'Nie udało się odczytać tego pliku: :filename. Pełny błąd znajdziesz w /dev/logs.',
        'none_readable' => 'Nie udało się odczytać żadnego z Twoich plików PDF z ICS. :detail',
        'full_error_in_logs' => 'Pełny błąd znajdziesz w /dev/logs.',
    ],
];
