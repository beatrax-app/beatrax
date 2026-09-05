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

    'chain' => [
        'heading' => 'Razrešavanje lanaca',
        'pending' => 'Razrešavanje lanaca nije počelo, pa lanci finansiranja nisu povezani.',
        'running' => 'Povezivanje lanaca finansiranja i razlaganje poravnanja sa izvoda.',
    ],

    'issues' => [
        'row' => 'Red :row: :reason',
        'file_stopped' => 'Fajl nije bilo moguće pročitati dalje od reda :row. Ništa posle tog reda nije uvezeno.',
        'file_none' => 'Fajl uopšte nije bilo moguće pročitati.',
        'detail' => 'Čitač je prijavio: :reason',
        'duplicate' => 'Red :row je već bio u tvojoj knjizi.',
        'more' => '+ :count nije navedeno',
    ],
];
