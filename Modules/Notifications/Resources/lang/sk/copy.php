<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import dokončený',
        'receipts' => 'Nájdené nové účtenky',
        'drift' => 'Zmenila sa opakovaná platba',
        'forecast' => 'Blíži sa nedostatok hotovosti',
        'budget_nudge' => 'Rozpočet je takmer minutý',
        'savings_prompt' => 'Existuje lacnejší plán',
        'ics_statement_ready' => 'Nový výpis ICS je pripravený',
        'payment_reminder_confident' => 'Splatnosť :day',
        'payment_reminder_hedged' => 'Splatnosť približne :day',
        'position_digest_daily' => 'Tvoja denná situácia',
        'position_digest_weekly' => 'Tvoja týždenná situácia',
    ],

    'body' => [
        'budget_nudge' => ':category — minuté :spent z :budget.',
        'receipts_matched' => 'Z tvojho e-mailu sa spárovala :count účtenka.|Z tvojho e-mailu sa spárovali :count účtenky.|Z tvojho e-mailu sa spárovalo :count účteniek.',
        'import_finished' => 'Importovala sa :count transakcia.|Importovali sa :count transakcie.|Importovalo sa :count transakcií.',
        'drift' => 'Opakovaná platba sa posunula :direction o :delta :currency.',
        'forecast' => 'Tvoj predpokladaný zostatok klesne v priebehu najbližších 30 dní pod nulu.',
        'ics_statement_ready' => 'Stiahni ho z portálu ICS a vlož do Beatraxu, aby boli výdavky z tejto karty aktuálne.',
        'payment_reminder_hedged' => ':name — očakávané okolo :day, :amount.',
        'payment_reminder_confident' => ':name — splatné :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mes.)',
    ],

    'drift_direction' => [
        'up' => 'nahor',
        'down' => 'nadol',
    ],

    'digest' => [
        'nothing_notable' => 'Nič nevyžaduje tvoju pozornosť.',
        'flow' => 'Príjmy :in, výdavky :out, netto :net.',
        'over_budget' => 'Doteraz nad rozpočet: :amount.',
        'payments_due' => '1 platba splatná v tomto období.|:count platby splatné v tomto období.|:count platieb splatných v tomto období.',
        'shortfall' => 'Blíži sa nedostatok hotovosti.',
    ],
];
