<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Cardul tău de credit',
    'h1' => 'Ia extrasele lunare în PDF',
    'lede' => 'Trage aici toate extrasele lunare în PDF — le combinăm într-o singură previzualizare.',

    'format_group_aria' => 'ICS exportă doar în PDF',
    'issuer_note' => 'ICS este deocamdată singurul emitent de carduri pe care îl putem citi, și doar extrasul lui în neerlandeză. Dacă ai card de la alt emitent, sari peste acest pas.',
    'got_it_as' => 'L-ai primit ca:',
    'badge_only_format' => 'singurul format',

    'mini' => [
        'login_label' => 'Autentifică-te',
        'statements_label' => 'Deschide extrasele',
        'months_label' => 'Alege lunile',
        'months_sub' => 'Un PDF pe lună',
        'download_label' => 'Descarcă',
    ],

    'drop_lead' => 'Trage aici PDF-urile ICS',
    'browse_files' => 'sau caută fișiere',
    'queue_aria' => 'Extrase PDF în așteptare',

    'skip' => 'Omite acest pas',
    'continue' => 'Continuă →',

    'errors' => [
        'required' => 'Trage aici extrasele lunare PDF descărcate din Mijn ICS.',
        'min' => 'Trage cel puțin un extras ICS în PDF înainte să continui.',
        'each_required' => 'Trage aici extrasul lunar PDF descărcat din Mijn ICS.',
        'each_max' => 'Unul dintre fișierele tale este prea mare. Extrasele ICS în PDF au de obicei sub 1 MB fiecare.',
        'each_extensions' => 'Unul dintre fișierele tale nu este PDF. Mijn ICS exportă doar PDF — încearcă cel mai recent extras lunar.',
        'file_unreadable' => ':filename nu a putut fi citit. Eroarea completă este în /dev/logs.',
        'none_readable' => 'Nu am putut citi niciunul dintre PDF-urile tale ICS. :detail',
        'full_error_in_logs' => 'Eroarea completă este în /dev/logs.',
    ],
];
