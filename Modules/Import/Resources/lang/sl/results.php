<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz je končan',
    'heading' => 'Uvoz je končan',

    'summary' => 'Uvožena :count transakcija|Uvoženi :count transakciji|Uvožene :count transakcije|Uvoženih :count transakcij',
    'summary_duplicates' => ' · preskočen :count dvojnik| · preskočena :count dvojnika| · preskočeni :count dvojniki| · preskočenih :count dvojnikov',
    'summary_enriched' => ' · obogatenih: :count',
    'summary_errors' => ' · :count napaka| · :count napaki| · :count napake| · :count napak',

    'show_duplicates' => 'Prikaži preskočene dvojnike (:count)',
    'duplicates_help' => 'Dvojniki so vrstice, ki so že v tvoji glavni knjigi — pri ponovnem uvozu se tiho preskočijo.',
    'show_errors' => 'Prikaži napake (:count)',
    'errors_help' => 'Napake so vrstice, ki jih ni bilo mogoče obdelati; v tvojo glavno knjigo niso bile dodane.',

    'upload_another' => 'Naloži še en izpisek',

    'issues' => [
        'row' => 'Vrstica :row: :reason',
        'file' => 'Datoteke ni bilo mogoče prebrati v celoti: :reason',
        'duplicate' => 'Vrstica :row je bila že v tvoji knjigi.',
        'more' => '+ :count ni navedenih',
        'unknown_reason' => 'Razlog ni bil zabeležen.',
    ],
];
