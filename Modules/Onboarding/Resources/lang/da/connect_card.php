<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Dit kreditkort (ICS)',
    'h1' => 'Hent dine månedlige kontoudtog som PDF',
    'lede' => 'Slip alle dine månedlige ICS-kontoudtog i PDF — vi samler dem i én forhåndsvisning.',

    'format_group_aria' => 'ICS eksporterer kun som PDF',
    'got_it_as' => 'Du fik det som:',
    'badge_only_format' => 'eneste format',

    'mini' => [
        'login_label' => 'Log ind',
        'statements_label' => 'Åbn kontoudtog',
        'months_label' => 'Vælg måneder',
        'months_sub' => 'Én PDF pr. måned',
        'download_label' => 'Hent',
    ],

    'drop_lead' => 'Slip dine ICS-PDF-filer her',
    'browse_files' => 'eller vælg filer',
    'queue_aria' => 'PDF-kontoudtog i køen',

    'skip' => 'Spring dette trin over',
    'continue' => 'Fortsæt →',

    'errors' => [
        'required' => 'Slip de månedlige PDF-kontoudtog, du hentede fra Mijn ICS.',
        'min' => 'Slip mindst ét ICS-PDF-kontoudtog, før du fortsætter.',
        'each_required' => 'Slip det månedlige PDF-kontoudtog, du hentede fra Mijn ICS.',
        'each_max' => 'En af dine filer er for stor. ICS-PDF-kontoudtog er normalt under 1 MB stykket.',
        'each_extensions' => 'En af dine filer er ikke en PDF. Mijn ICS eksporterer kun PDF — prøv det seneste månedlige kontoudtog.',
        'file_unreadable' => 'Kunne ikke læse :filename. Hele fejlen står i /dev/logs.',
        'none_readable' => 'Vi kunne ikke læse nogen af dine ICS-PDF-filer. :detail',
        'full_error_in_logs' => 'Hele fejlen står i /dev/logs.',
    ],
];
