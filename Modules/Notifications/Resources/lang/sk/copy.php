<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import dokončený',
        'receipts' => 'Nájdené nové účtenky',
        'manual_entry' => 'Pokladničná kniha aktualizovaná',
        'migration_finished' => 'Migrácia dokončená',
        'drift' => 'Zmenila sa opakovaná platba',
        'forecast' => 'Blíži sa nedostatok hotovosti',
        'budget_nudge' => 'Rozpočet je takmer minutý',
        'budget_nudge_spent' => 'Rozpočet je minutý',
        'budget_nudge_over' => 'Rozpočet je prekročený',
        'savings_prompt' => 'Miesto, kde môžeš ušetriť',
        'ics_statement_ready' => 'Nový výpis ICS je pripravený',
        'payment_reminder_confident' => 'Splatnosť :day (:date)',
        'payment_reminder_hedged' => 'Splatnosť približne :day (:date)',
        'position_digest_daily' => 'Tvoja denná situácia',
        'position_digest_weekly' => 'Tvoja týždenná situácia',
    ],

    'body' => [
        'budget_nudge' => ':category — minuté :spent z :budget.',
        'receipts_matched' => 'Z tvojho e-mailu sa spárovala :count účtenka.|Z tvojho e-mailu sa spárovali :count účtenky.|Z tvojho e-mailu sa spárovalo :count účteniek.',
        'import_finished' => 'Importovala sa :count transakcia.|Importovali sa :count transakcie.|Importovalo sa :count transakcií.',
        'manual_entry' => 'Ručne sa pridal :count záznam.|Ručne sa pridali :count záznamy.|Ručne sa pridalo :count záznamov.',
        'migration_finished' => 'Tvoj rozpočet je prenesený, vrátane :count transakcie.|Tvoj rozpočet je prenesený, vrátane :count transakcií.|Tvoj rozpočet je prenesený, vrátane :count transakcií.',
        'drift' => 'Opakovaná platba sa posunula :direction o :amount.',
        'forecast' => 'Tvoj predpokladaný zostatok klesne :date pod nulu.',
        'forecast_buffer' => 'Tvoj predpokladaný zostatok klesne :date pod rezervu :buffer.',
        'ics_statement_ready' => 'Stiahni ho z portálu ICS a vlož do Beatraxu, aby boli výdavky z tejto karty aktuálne.',
        'payment_reminder_hedged' => ':name — očakávané okolo :day (:date), :amount.',
        'payment_reminder_confident' => ':name — splatné :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'nahor',
        'down' => 'nadol',
    ],

    'digest' => [
        'nothing_notable' => 'Nič nevyžaduje tvoju pozornosť.',
        'flow' => 'Príjmy :in, výdavky :out, netto :net.',
        'over_budget' => 'Doteraz nad rozpočet: :amount.',
        'payments_due' => ':count platba splatná v tomto období.|:count platby splatné v tomto období.|:count platieb splatných v tomto období.',
        'shortfall' => 'Blíži sa nedostatok hotovosti.',
    ],
];
