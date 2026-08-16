<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj PayPal račun',
    'h1' => 'Poveži svoj PayPal račun',

    'lede_html' => 'Ispusti izvoz pojedinosti PayPal transakcija — na nizozemskom PayPal računu naveden kao <em lang="nl">Rapport Transactiegegevens</em>. Izvješće o stanju (<span lang="nl">Saldorapport</span>) neće raditi — trebamo podatke po događaju.',

    'format_group_aria' => 'PayPal izvozi isključivo u CSV',
    'got_it_as' => 'Preuzeto kao:',
    'badge_only_format' => 'jedini format',

    'mini' => [
        'login_label' => 'Prijavi se',
        'custom_label' => 'Prilagođeni izvodi',
        'range_label' => 'Odaberi razdoblje',
        'range_sub' => 'Zadnjih 12 mjeseci',
        'download_label' => 'Preuzmi kao CSV',
    ],

    'drop_lead' => 'Ovdje ispusti CSV s pojedinostima transakcija',
    'browse_file' => 'ili potraži datoteku',

    'file_ready' => '· ✓ spremno',

    'skip' => 'Preskoči ovaj korak',
    'continue' => 'Nastavi →',

    'errors' => [
        'required' => 'Najprije ispusti svoj PayPal Rapport Transactiegegevens CSV u okvir.',
        'max' => 'Ta datoteka je prevelika. Izvozi PayPal Rapport Transactiegegevens obično su znatno manji od 10 MB.',
        'extensions' => 'Ta datoteka ne izgleda kao PayPal CSV. S PayPala preuzmi Rapport Transactiegegevens (ne izvješće o stanju Saldorapport) kao CSV.',
        'unreadable' => 'Ovu datoteku nije bilo moguće pročitati. Cijela pogreška je u /dev/logs.',
    ],
];
