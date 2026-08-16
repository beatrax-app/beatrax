<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tavo PayPal paskyra',
    'h1' => 'Prijunk savo PayPal paskyrą',

    'lede_html' => 'Įkelk savo PayPal operacijų duomenų eksportą — olandiškoje PayPal paskyroje jis vadinamas <em lang="nl">Rapport Transactiegegevens</em>. Likučio ataskaita (<span lang="nl">Saldorapport</span>) netiks — mums reikia duomenų apie kiekvieną įvykį.',

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

    'drop_lead' => 'Vilk operacijų duomenų CSV čia',
    'browse_file' => 'arba pasirink failą',

    'file_ready' => '· ✓ paruošta',

    'skip' => 'Praleisti šį žingsnį',
    'continue' => 'Tęsti →',

    'errors' => [
        'required' => 'Pirmiausia įkelk savo PayPal Rapport Transactiegegevens CSV į laukelį.',
        'max' => 'Šis failas per didelis. PayPal Rapport Transactiegegevens eksportai paprastai būna gerokai mažesni nei 10 MB.',
        'extensions' => 'Šis failas nepanašus į PayPal CSV. Iš PayPal atsisiųsk Rapport Transactiegegevens (ne Saldorapport likučio ataskaitą) CSV formatu.',
        'unreadable' => 'Šio failo perskaityti nepavyko. Visą klaidą rasi /dev/logs.',
    ],
];
