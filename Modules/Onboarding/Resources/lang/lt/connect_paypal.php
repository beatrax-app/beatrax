<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tavo PayPal paskyra',
    'h1' => 'Prijunk savo PayPal paskyrą',

    'lede_html' => 'Įkelk savo PayPal operacijų eksportą — po vieną eilutę kiekvienai operacijai, o ne likučio suvestinę. PayPal savo ataskaitas pavadina tavo paskyros kalba, o kol kas skaitome olandišką porą: <em lang="nl">Rapport Transactiegegevens</em>, o ne <span lang="nl">Saldorapport</span>. Jei tavoji išeina kita kalba, prieš atsisiųsdamas perjunk PayPal į olandų kalbą.',

    'format_group_aria' => 'PayPal eksportuoja tik CSV',
    'got_it_as' => 'Turiu kaip:',
    'badge_only_format' => 'vienintelis formatas',

    'mini' => [
        'login_label' => 'Prisijunk',
        'custom_label' => 'Pasirinktiniai išrašai',
        'range_label' => 'Pasirink laikotarpį',
        'range_sub' => 'Paskutiniai 12 mėnesių',
        'download_label' => 'Atsisiųsk CSV formatu',
    ],

    'drop_lead' => 'Įkelk čia savo operacijų eksportą',
    'browse_file' => 'arba pasirink failą',

    'file_ready' => '· ✓ paruošta',

    'skip' => 'Praleisti šį žingsnį',
    'continue' => 'Tęsti →',

    'errors' => [
        'required' => 'Pirmiausia įkelk į laukelį savo PayPal operacijų eksportą.',
        'max' => 'Šis failas per didelis. PayPal operacijų eksportas paprastai būna gerokai mažesnis nei 10 MB.',
        'extensions' => 'Šis failas nepanašus į PayPal CSV. Atsisiųsk operacijų eksportą — po vieną eilutę kiekvienai operacijai, o ne likučio suvestinę — CSV formatu.',
        'unreadable' => 'Šio failo perskaityti nepavyko. Visą klaidą rasi /dev/logs.',
    ],
];
