<?php

declare(strict_types=1);

return [
    'page_title' => 'Uvoz je dovršen',
    'heading' => 'Uvoz je dovršen',

    'summary' => 'Uvezena :count transakcija|Uvezene :count transakcije|Uvezeno :count transakcija',
    'summary_duplicates' => ' · preskočen :count duplikat| · preskočena :count duplikata| · preskočeno :count duplikata',
    'summary_enriched' => ' · obogaćenih: :count',
    'summary_errors' => ' · :count pogreška| · :count pogreške| · :count pogrešaka',

    'show_duplicates' => 'Prikaži preskočene duplikate (:count)',
    'duplicates_help' => 'Duplikati su retci koji već postoje u tvojoj glavnoj knjizi — pri ponovnom uvozu tiho se preskaču.',
    'show_errors' => 'Prikaži pogreške (:count)',
    'errors_help' => 'Pogreške su retci koje nije bilo moguće obraditi; nisu dodani u tvoju glavnu knjigu.',

    'upload_another' => 'Učitaj još jedan izvod',

    'chain' => [
        'heading' => 'Rješavanje lanaca…',
        'pending' => 'U redu čekanja. Rješavanje lanaca uskoro počinje.',
        'running' => 'Povezivanje lanaca financiranja i razlaganje namira s izvoda.',
    ],

    'issues' => [
        'row' => 'Redak :row: :reason',
        'file_stopped' => 'Datoteku nije bilo moguće pročitati dalje od retka :row. Ništa nakon tog retka nije uvezeno.',
        'file_none' => 'Datoteku uopće nije bilo moguće pročitati.',
        'detail' => 'Čitač je prijavio: :reason',
        'duplicate' => 'Redak :row već je bio u tvojoj knjizi.',
        'more' => '+ :count nije navedeno',
    ],
];
