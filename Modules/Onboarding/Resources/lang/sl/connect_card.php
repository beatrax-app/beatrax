<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoja kreditna kartica',
    'h1' => 'Prenesi svoje mesečne izpiske v PDF',
    'lede' => 'Spusti vse svoje mesečne izpiske v PDF — združili jih bomo v en predogled.',

    'format_group_aria' => 'ICS izvaža samo v PDF',
    'issuer_note' => 'ICS je zaenkrat edini izdajatelj kartic, ki ga znamo prebrati, in to le njegov izpisek v nizozemščini. Če je tvoja kartica drugega izdajatelja, preskoči ta korak.',
    'got_it_as' => 'Imam jih kot:',
    'badge_only_format' => 'edini format',

    'mini' => [
        'login_label' => 'Prijavi se',
        'statements_label' => 'Odpri izpiske',
        'months_label' => 'Izberi mesece',
        'months_sub' => 'En PDF na mesec',
        'download_label' => 'Prenesi',
    ],

    'drop_lead' => 'Datoteke PDF iz ICS spusti sem',
    'browse_files' => 'ali poišči datoteke',
    'queue_aria' => 'Izpiski PDF v čakalni vrsti',

    'skip' => 'Preskoči ta korak',
    'continue' => 'Nadaljuj →',

    'errors' => [
        'required' => 'Spusti mesečne izpiske PDF, prenesene iz Mijn ICS.',
        'min' => 'Preden nadaljuješ, spusti vsaj en izpisek ICS v PDF.',
        'each_required' => 'Spusti mesečni izpisek PDF, prenesen iz Mijn ICS.',
        'each_max' => 'Ena od tvojih datotek je prevelika. Izpiski ICS v PDF so običajno manjši od 1 MB.',
        'each_extensions' => 'Ena od tvojih datotek ni PDF. Mijn ICS izvaža samo PDF — poskusi z najnovejšim mesečnim izpiskom.',
        'file_unreadable' => 'Datoteke :filename ni bilo mogoče prebrati. Celotna napaka je v /dev/logs.',
        'none_readable' => 'Nobene od tvojih datotek PDF iz ICS nismo mogli prebrati. :detail',
        'full_error_in_logs' => 'Celotna napaka je v /dev/logs.',
    ],
];
