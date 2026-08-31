<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import dokončen',
        'receipts' => 'Nalezeny nové účtenky',
        'manual_entry' => 'Pokladní kniha aktualizována',
        'migration_finished' => 'Migrace dokončena',
        'drift' => 'Opakovaná platba se změnila',
        'forecast' => 'Blíží se nedostatek peněz',
        'budget_nudge' => 'Rozpočet je téměř vyčerpaný',
        'budget_nudge_spent' => 'Rozpočet je vyčerpaný',
        'budget_nudge_over' => 'Rozpočet je překročený',
        'savings_prompt' => 'Místo, kde můžeš ušetřit',
        'ics_statement_ready' => 'Nový výpis z účtu ICS je připravený',
        'payment_reminder_confident' => 'Splatnost: :day (:date)',
        'payment_reminder_hedged' => 'Splatnost přibližně: :day (:date)',
        'position_digest_daily' => 'Tvá denní situace',
        'position_digest_weekly' => 'Tvá týdenní situace',
    ],

    'body' => [
        'budget_nudge' => ':category — utraceno :spent z :budget.',
        'receipts_matched' => 'Z tvého e-mailu se spárovala :count účtenka.|Z tvého e-mailu se spárovaly :count účtenky.|Z tvého e-mailu se spárovalo :count účtenek.',
        'import_finished' => 'Naimportována :count transakce.|Naimportovány :count transakce.|Naimportováno :count transakcí.',
        'manual_entry' => 'Ručně přidán :count záznam.|Ručně přidány :count záznamy.|Ručně přidáno :count záznamů.',
        'migration_finished' => 'Tvůj rozpočet je převedený, včetně :count transakce.|Tvůj rozpočet je převedený, včetně :count transakcí.|Tvůj rozpočet je převedený, včetně :count transakcí.',
        'drift' => 'Opakovaná platba šla :direction o :amount.',
        'forecast' => 'Tvůj předpokládaný zůstatek klesne :date pod nulu.',
        'forecast_buffer' => 'Tvůj předpokládaný zůstatek klesne :date pod rezervu :buffer.',
        'ics_statement_ready' => 'Stáhni si ho z portálu ICS a vlož do Beatraxu, ať máš útraty z této karty aktuální.',
        'payment_reminder_hedged' => ':name — očekáváno přibližně :day (:date), :amount.',
        'payment_reminder_confident' => ':name — splatnost :day (:date), :amount.',
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
