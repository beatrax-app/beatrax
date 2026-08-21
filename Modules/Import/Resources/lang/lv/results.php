<?php

declare(strict_types=1);

return [
    'page_title' => 'Imports pabeigts',
    'heading' => 'Imports pabeigts',

    'summary' => 'Importēti :count darījumu|Importēts :count darījums|Importēti :count darījumi',
    'summary_duplicates' => ' · izlaisti :count dublikātu| · izlaists :count dublikāts| · izlaisti :count dublikāti',
    'summary_enriched' => ' · papildināti: :count',
    'summary_errors' => ' · :count kļūdu| · :count kļūda| · :count kļūdas',

    'show_duplicates' => 'Rādīt izlaistos dublikātus (:count)',
    'duplicates_help' => 'Dublikāti ir rindas, kas jau ir jūsu virsgrāmatā — atkārtota importa laikā tās tiek klusi izlaistas.',
    'show_errors' => 'Rādīt kļūdas (:count)',
    'errors_help' => 'Kļūdas ir rindas, kuras neizdevās nolasīt; tās netika pievienotas jūsu virsgrāmatai.',

    'upload_another' => 'Augšupielādēt citu konta izrakstu',

    'issues' => [
        'row' => 'Rinda :row: :reason',
        'file' => 'Failu neizdevās nolasīt pilnībā: :reason',
        'duplicate' => 'Rinda :row jau bija tavā virsgrāmatā.',
        'more' => '+ :count nav uzskaitīts',
        'unknown_reason' => 'Iemesls netika reģistrēts.',
    ],
];
