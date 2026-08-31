<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj PayPal račun',
    'h1' => 'Poveži svoj PayPal račun',

    'lede_html' => 'Ispusti izvoz prometa s PayPala — jedan redak po transakciji, a ne sažetak stanja. PayPal svoja izvješća imenuje na jeziku tvog računa, a zasad čitamo nizozemski par: <em lang="nl">Rapport Transactiegegevens</em>, ne <span lang="nl">Saldorapport</span>. Ako tvoj izađe na drugom jeziku, prebaci PayPal na nizozemski prije preuzimanja.',

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

    'drop_lead' => 'Ispusti ovdje svoj izvoz prometa',
    'browse_file' => 'ili potraži datoteku',

    'file_ready' => '· ✓ spremno',

    'skip' => 'Preskoči ovaj korak',
    'continue' => 'Nastavi →',

    'errors' => [
        'required' => 'Najprije ispusti izvoz prometa s PayPala u okvir.',
        'max' => 'Ta datoteka je prevelika. Izvoz prometa s PayPala obično je znatno manji od 10 MB.',
        'extensions' => 'Ta datoteka ne izgleda kao PayPal CSV. Preuzmi izvoz prometa — jedan redak po transakciji, a ne sažetak stanja — kao CSV.',
        'unreadable' => 'Ovu datoteku nije bilo moguće pročitati. Cijela pogreška je u /dev/logs.',
    ],
];
