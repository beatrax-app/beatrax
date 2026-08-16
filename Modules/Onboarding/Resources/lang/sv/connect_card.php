<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Ditt kreditkort (ICS)',
    'h1' => 'Hämta dina månadskontoutdrag som PDF',
    'lede' => 'Släpp alla dina månatliga ICS-kontoutdrag i PDF — vi slår ihop dem till en förhandsgranskning.',

    'format_group_aria' => 'ICS exporterar endast som PDF',
    'got_it_as' => 'Du fick det som:',
    'badge_only_format' => 'enda formatet',

    'mini' => [
        'login_label' => 'Logga in',
        'statements_label' => 'Öppna kontoutdrag',
        'months_label' => 'Välj månader',
        'months_sub' => 'En PDF per månad',
        'download_label' => 'Ladda ner',
    ],

    'drop_lead' => 'Släpp dina ICS-PDF-filer här',
    'browse_files' => 'eller bläddra efter filer',
    'queue_aria' => 'PDF-kontoutdrag i kön',

    'skip' => 'Hoppa över det här steget',
    'continue' => 'Fortsätt →',

    'errors' => [
        'required' => 'Släpp de månatliga PDF-kontoutdrag du laddade ner från Mijn ICS.',
        'min' => 'Släpp minst ett ICS-PDF-kontoutdrag innan du fortsätter.',
        'each_required' => 'Släpp det månatliga PDF-kontoutdrag du laddade ner från Mijn ICS.',
        'each_max' => 'En av dina filer är för stor. ICS-PDF-kontoutdrag är normalt under 1 MB styck.',
        'each_extensions' => 'En av dina filer är inte en PDF. Mijn ICS exporterar bara PDF — testa det senaste månadskontoutdraget.',
        'file_unreadable' => 'Kunde inte läsa :filename. Hela felet finns i /dev/logs.',
        'none_readable' => 'Vi kunde inte läsa någon av dina ICS-PDF-filer. :detail',
        'full_error_in_logs' => 'Hela felet finns i /dev/logs.',
    ],
];
