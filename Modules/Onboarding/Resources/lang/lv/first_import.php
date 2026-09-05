<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Pārskatīšana un apstiprināšana',
    'h1' => 'Pārskatiet visu, ko atradām',

    'lede_counts' => ':transactions no :sources.',
    'source' => ':count avotiem|:count avota|:count avotiem',
    'lede_confirm' => 'Apstipriniet sākuma atlikumus un pēc tam apstipriniet importu.',

    'empty' => 'Vēl nav ko pārskatīt. Iepriekšējos soļos ievelciet konta izrakstu, lai šeit redzētu savus darījumus.',

    'sb_eyebrow_label' => '🧮 SĀKUMA ATLIKUMI ·',
    'account_detected' => ':count ATKLĀTU KONTU|:count ATKLĀTS KONTS|:count ATKLĀTI KONTI',
    'sb_lede' => 'Katram kontam noteicām sākuma atlikumu. Apstipriniet vai izlabojiet to, pirms apstiprinām importu.',

    'txn' => ':count darījumu|:count darījums|:count darījumi',
    'to_commit' => 'apstiprināšanai ·',
    'already_imported' => ':count jau importētu|:count jau importēts|:count jau importēti',
    'commit_committing' => 'Apstiprina…',
    'commit_count' => 'Apstiprināt visu (:count darījumu) →|Apstiprināt visu (:count darījums) →|Apstiprināt visu (:count darījumi) →',
    'commit_empty' => 'Apstiprināt visu (—) →',
    'skip' => 'Pagaidām izlaist',

    'errors' => [
        'nothing_to_commit' => 'Nav ko apstiprināt.',
        'commit_failed' => 'Neizdevās apstiprināt jūsu konta izrakstus. Nekas netika mainīts — mēģiniet vēlreiz.',
    ],

    'section' => [
        'from_prefix' => 'NO ',
        'from_bank' => 'NO JŪSU BANKAS KONTA IZRAKSTA',
        'from_ics' => 'NO JŪSU ICS KARTES IZRAKSTIEM',
        'from_paypal' => 'NO PAYPAL',
        'row' => ':count RINDU|:count RINDA|:count RINDAS',
        'badge_ready' => '✓ GATAVS',
        'badge_empty' => 'TUKŠS',
        'badge_error' => 'JĀAUGŠUPIELĀDĒ VĒLREIZ',
        'error_body' => 'Neizdevās nolasīt visus šī avota failus. Izmēģiniet citu failu →',
        'partial_body' => 'Vienu no šiem failiem neizdevās nolasīt pilnībā, tāpēc tas tika izlaists viss: :reason',
        'empty_body' => 'Šis konta izraksts ir tukšs.',
        'col_date' => 'Datums',
        'col_type' => 'Veids',
        'col_counterparty' => 'Darījuma partneris',
        'col_amount' => 'Summa',
        'load_more' => 'Ielādēt vairāk (vēl :remaining)',
        'rows_shown' => 'rādītas :count rindu|rādīta :count rinda|rādītas :count rindas',
    ],
];
