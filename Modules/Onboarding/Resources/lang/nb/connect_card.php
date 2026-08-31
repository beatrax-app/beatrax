<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Kredittkortet ditt',
    'h1' => 'Hent de månedlige kontoutskriftene dine som PDF',
    'lede' => 'Slipp alle de månedlige kontoutskriftene dine i PDF — vi slår dem sammen til én forhåndsvisning.',

    'format_group_aria' => 'ICS eksporterer bare som PDF',
    'issuer_note' => 'ICS er foreløpig den eneste kortutstederen vi kan lese, og bare kontoutskriften på nederlandsk. Er kortet ditt fra en annen utsteder, hopp over dette trinnet.',
    'got_it_as' => 'Du fikk det som:',
    'badge_only_format' => 'eneste format',

    'mini' => [
        'login_label' => 'Logg inn',
        'statements_label' => 'Åpne kontoutskrifter',
        'months_label' => 'Velg måneder',
        'months_sub' => 'Én PDF per måned',
        'download_label' => 'Last ned',
    ],

    'drop_lead' => 'Slipp ICS-PDF-filene dine her',
    'browse_files' => 'eller velg filer',
    'queue_aria' => 'PDF-kontoutskrifter i køen',

    'skip' => 'Hopp over dette trinnet',
    'continue' => 'Fortsett →',

    'errors' => [
        'required' => 'Slipp de månedlige PDF-kontoutskriftene du lastet ned fra Mijn ICS.',
        'min' => 'Slipp minst én ICS-PDF-kontoutskrift før du fortsetter.',
        'each_required' => 'Slipp den månedlige PDF-kontoutskriften du lastet ned fra Mijn ICS.',
        'each_max' => 'En av filene dine er for stor. ICS-PDF-kontoutskrifter er vanligvis under 1 MB hver.',
        'each_extensions' => 'En av filene dine er ikke en PDF. Mijn ICS eksporterer bare PDF — prøv den siste månedlige kontoutskriften.',
        'file_unreadable' => 'Kunne ikke lese :filename. Hele feilen står i /dev/logs.',
        'none_readable' => 'Vi kunne ikke lese noen av ICS-PDF-filene dine. :detail',
        'full_error_in_logs' => 'Hele feilen står i /dev/logs.',
    ],
];
