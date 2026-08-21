<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import dokončen',
        'receipts' => 'Nalezeny nové účtenky',
        'drift' => 'Opakovaná platba se změnila',
        'forecast' => 'Blíží se nedostatek peněz',
        'budget_nudge' => 'Rozpočet je téměř vyčerpaný',
        'savings_prompt' => 'Existuje levnější tarif',
        'ics_statement_ready' => 'Nový výpis z účtu ICS je připravený',
        'payment_reminder_confident' => 'Splatnost: :day',
        'payment_reminder_hedged' => 'Splatnost přibližně: :day',
        'position_digest_daily' => 'Tvá denní situace',
        'position_digest_weekly' => 'Tvá týdenní situace',
    ],

    'body' => [
        'budget_nudge' => ':category — utraceno :spent z :budget.',
        'receipts_matched' => 'Z tvého e-mailu se spárovala :count účtenka.|Z tvého e-mailu se spárovaly :count účtenky.|Z tvého e-mailu se spárovalo :count účtenek.',
        'import_finished' => 'Naimportována :count transakce.|Naimportovány :count transakce.|Naimportováno :count transakcí.',
        'drift' => 'Opakovaná platba šla :direction o :amount.',
        'forecast' => 'Tvůj předpokládaný zůstatek klesne během příštích 30 dní pod nulu.',
        'ics_statement_ready' => 'Stáhni si ho z portálu ICS a vlož do Beatraxu, ať máš útraty z této karty aktuální.',
        'payment_reminder_hedged' => ':name — očekáváno přibližně :day, :amount.',
        'payment_reminder_confident' => ':name — splatnost :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/měs.)',
    ],

    'drift_direction' => [
        'up' => 'nahoru',
        'down' => 'dolů',
    ],

    'digest' => [
        'nothing_notable' => 'Nic nevyžaduje tvou pozornost.',
        'flow' => 'Příjmy :in, výdaje :out, netto :net.',
        'over_budget' => 'Zatím nad rozpočet o :amount.',
        'payments_due' => ':count platba splatná v tomto období.|:count platby splatné v tomto období.|:count plateb splatných v tomto období.',
        'shortfall' => 'Blíží se nedostatek peněz.',
    ],
];
