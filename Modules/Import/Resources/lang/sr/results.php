<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz je završen',
    'heading' => 'Uvoz je završen',

    'summary' => 'Uvezena :count transakcija|Uvezene :count transakcije|Uvezeno :count transakcija',
    'summary_duplicates' => ' · preskočen :count duplikat| · preskočena :count duplikata| · preskočeno :count duplikata',
    'summary_enriched' => ' · obogaćenih: :count',
    'summary_errors' => ' · :count greška| · :count greške| · :count grešaka',

    'show_duplicates' => 'Prikaži preskočene duplikate (:count)',
    'duplicates_help' => 'Duplikati su redovi koji već postoje u tvojoj glavnoj knjizi — pri ponovnom uvozu tiho se preskaču.',
    'show_errors' => 'Prikaži greške (:count)',
    'errors_help' => 'Greške su redovi koje nije bilo moguće obraditi; nisu dodati u tvoju glavnu knjigu.',

    'upload_another' => 'Otpremi još jedan izvod',

    'issues' => [
        'row' => 'Red :row: :reason',
        'file' => 'Fajl nije bilo moguće pročitati u celosti: :reason',
        'duplicate' => 'Red :row je već bio u tvojoj knjizi.',
        'more' => '+ :count nije navedeno',
        'unknown_reason' => 'Razlog nije zabeležen.',
    ],
];
