<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Tuonti valmis',
        'receipts' => 'Uusia kuitteja löytyi',
        'manual_entry' => 'Kassakirja päivitetty',
        'migration_finished' => 'Siirto valmis',
        'drift' => 'Toistuva veloitus muuttui',
        'forecast' => 'Kassavaje edessä',
        'budget_nudge' => 'Budjetti lähes käytetty',
        'budget_nudge_spent' => 'Budjetti käytetty',
        'budget_nudge_over' => 'Budjetti ylitetty',
        'savings_prompt' => 'Tässä voisit säästää',
        'ics_statement_ready' => 'Uusi ICS-tiliote valmiina',
        'payment_reminder_confident' => 'Maksu erääntyy :day (:date)',
        'payment_reminder_hedged' => 'Maksu erääntyy noin :day (:date)',
        'position_digest_daily' => 'Päivittäinen tilanteesi',
        'position_digest_weekly' => 'Viikoittainen tilanteesi',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent / :budget käytetty.',
        'receipts_matched' => ':count kuitti täsmäytettiin sähköpostistasi.|:count kuittia täsmäytettiin sähköpostistasi.',
        'import_finished' => ':count tapahtuma tuotu.|:count tapahtumaa tuotu.',
        'manual_entry' => ':count merkintä lisätty käsin.|:count merkintää lisätty käsin.',
        'migration_finished' => 'Budjettisi siirtyi, mukana :count tapahtuma.|Budjettisi siirtyi, mukana :count tapahtumaa.',
        'drift' => 'Toistuvan veloituksen summa :direction :amount.',
        'forecast' => 'Ennustettu saldosi painuu nollan alle :date.',
        'forecast_buffer' => 'Ennustettu saldosi painuu :buffer puskurisi alle :date.',
        'ics_statement_ready' => 'Lataa se ICS-portaalista ja pudota Beatraxiin, niin tämän kortin kulutus pysyy ajan tasalla.',
        'payment_reminder_hedged' => ':name — odotettavissa noin :day (:date), :amount.',
        'payment_reminder_confident' => ':name — erääntyy :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'nousi',
        'down' => 'laski',
    ],

    'digest' => [
        'nothing_notable' => 'Mikään ei vaadi huomiotasi.',
        'flow' => 'Sisään :in, ulos :out, netto :net.',
        'net_worth' => 'Nettovarallisuus :amount.',
        'over_budget' => ':amount yli budjetin toistaiseksi.',
        'payments_due' => ':count maksu erääntyy tällä jaksolla.|:count maksua erääntyy tällä jaksolla.',
        'shortfall' => 'Edessä on kassavaje.',
        'forecast_not_run' => 'Kassavirtaennustetta ei ole vielä ajettu.',
    ],
];
