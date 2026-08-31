<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tavo kredito kortelė',
    'h1' => 'Parsisiųsk mėnesinius PDF išrašus',
    'lede' => 'Įkelk visus mėnesinius PDF išrašus — juos sujungsime į vieną peržiūrą.',

    'format_group_aria' => 'ICS eksportuoja tik PDF',
    'issuer_note' => 'ICS kol kas yra vienintelis kortelių išdavėjas, kurio išrašą mokame perskaityti, ir tik olandų kalba. Jei tavo kortelė iš kito išdavėjo, praleisk šį žingsnį.',
    'got_it_as' => 'Turiu kaip:',
    'badge_only_format' => 'vienintelis formatas',

    'mini' => [
        'login_label' => 'Prisijunk',
        'statements_label' => 'Atverk išrašus',
        'months_label' => 'Pasirink mėnesius',
        'months_sub' => 'Po vieną PDF kiekvienam mėnesiui',
        'download_label' => 'Atsisiųsk',
    ],

    'drop_lead' => 'Vilk ICS PDF failus čia',
    'browse_files' => 'arba pasirink failus',
    'queue_aria' => 'Eilėje esantys PDF išrašai',

    'skip' => 'Praleisti šį žingsnį',
    'continue' => 'Tęsti →',

    'errors' => [
        'required' => 'Įkelk mėnesinius PDF išrašus, atsisiųstus iš Mijn ICS.',
        'min' => 'Prieš tęsdamas įkelk bent vieną ICS PDF išrašą.',
        'each_required' => 'Įkelk mėnesinį PDF išrašą, atsisiųstą iš Mijn ICS.',
        'each_max' => 'Vienas iš tavo failų per didelis. ICS PDF išrašai paprastai būna mažesni nei 1 MB.',
        'each_extensions' => 'Vienas iš tavo failų nėra PDF. Mijn ICS eksportuoja tik PDF — pabandyk naujausią mėnesinį išrašą.',
        'file_unreadable' => 'Nepavyko perskaityti :filename. Visą klaidą rasi /dev/logs.',
        'none_readable' => 'Nepavyko perskaityti nė vieno tavo ICS PDF failo. :detail',
        'full_error_in_logs' => 'Visą klaidą rasi /dev/logs.',
    ],
];
