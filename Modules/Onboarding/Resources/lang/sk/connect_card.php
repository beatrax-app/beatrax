<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoja kreditná karta',
    'h1' => 'Stiahni si mesačné výpisy v PDF',
    'lede' => 'Presuň sem všetky mesačné výpisy v PDF — spojíme ich do jednej ukážky.',

    'format_group_aria' => 'ICS exportuje len do PDF',
    'issuer_note' => 'ICS je zatiaľ jediný vydavateľ kariet, ktorého vieme prečítať, a to len jeho výpis v holandčine. Ak máš kartu od iného vydavateľa, tento krok preskoč.',
    'got_it_as' => 'Mám ho ako:',
    'badge_only_format' => 'jediný formát',

    'mini' => [
        'login_label' => 'Prihlás sa',
        'statements_label' => 'Otvor výpisy',
        'months_label' => 'Vyber mesiace',
        'months_sub' => 'Jedno PDF na mesiac',
        'download_label' => 'Stiahni',
    ],

    'drop_lead' => 'Sem presuň svoje PDF od ICS',
    'browse_files' => 'alebo vyber súbory z disku',
    'queue_aria' => 'Výpisy v PDF vo fronte',

    'skip' => 'Preskočiť tento krok',
    'continue' => 'Pokračovať →',

    'errors' => [
        'required' => 'Presuň sem mesačné výpisy v PDF stiahnuté z Mijn ICS.',
        'min' => 'Skôr než budeš pokračovať, presuň sem aspoň jeden výpis ICS v PDF.',
        'each_required' => 'Presuň sem mesačný výpis v PDF stiahnutý z Mijn ICS.',
        'each_max' => 'Jeden z tvojich súborov je príliš veľký. Výpisy ICS v PDF mávajú menej než 1 MB.',
        'each_extensions' => 'Jeden z tvojich súborov nie je PDF. Mijn ICS exportuje len do PDF — skús najnovší mesačný výpis.',
        'file_unreadable' => 'Súbor :filename sa nepodarilo prečítať. Úplná chyba je v /dev/logs.',
        'none_readable' => 'Nepodarilo sa nám prečítať ani jeden z tvojich PDF od ICS. :detail',
        'full_error_in_logs' => 'Úplná chyba je v /dev/logs.',
    ],
];
