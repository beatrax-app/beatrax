<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoj PayPal nalog',
    'h1' => 'Poveži svoj PayPal nalog',

    'lede_html' => 'Prevuci izvoz prometa sa PayPala — jedan red po transakciji, a ne sažetak stanja. PayPal svoje izveštaje imenuje na jeziku tvog naloga, a zasad čitamo holandski par: <em lang="nl">Rapport Transactiegegevens</em>, ne <span lang="nl">Saldorapport</span>. Ako tvoj izađe na drugom jeziku, prebaci PayPal na holandski pre preuzimanja.',

    'format_group_aria' => 'PayPal izvozi isključivo u CSV',
    'got_it_as' => 'Preuzeto kao:',
    'badge_only_format' => 'jedini format',

    'mini' => [
        'login_label' => 'Prijavi se',
        'custom_label' => 'Prilagođeni izvodi',
        'range_label' => 'Izaberi period',
        'range_sub' => 'Poslednjih 12 meseci',
        'download_label' => 'Preuzmi kao CSV',
    ],

    'drop_lead' => 'Prevuci ovde svoj izvoz prometa',
    'browse_file' => 'ili potraži datoteku',

    'file_ready' => '· ✓ spremno',

    'skip' => 'Preskoči ovaj korak',
    'continue' => 'Nastavi →',

    'errors' => [
        'required' => 'Prvo prevuci izvoz prometa sa PayPala u okvir.',
        'max' => 'Ta datoteka je prevelika. Izvoz prometa sa PayPala obično je znatno manji od 10 MB.',
        'extensions' => 'Ta datoteka ne izgleda kao PayPal CSV. Preuzmi izvoz prometa — jedan red po transakciji, a ne sažetak stanja — kao CSV.',
        'unreadable' => 'Ovu datoteku nije bilo moguće pročitati. Cela greška je u /dev/logs.',
    ],
];
