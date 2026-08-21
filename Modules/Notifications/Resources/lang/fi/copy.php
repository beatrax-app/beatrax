<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Tuonti valmis',
        'receipts' => 'Uusia kuitteja löytyi',
        'drift' => 'Toistuva veloitus muuttui',
        'forecast' => 'Kassavaje edessä',
        'budget_nudge' => 'Budjetti lähes käytetty',
        'savings_prompt' => 'Halvempi sopimus löytyi',
        'ics_statement_ready' => 'Uusi ICS-tiliote valmiina',
        'payment_reminder_confident' => 'Maksu erääntyy :day',
        'payment_reminder_hedged' => 'Maksu erääntyy noin :day',
        'position_digest_daily' => 'Päivittäinen tilanteesi',
        'position_digest_weekly' => 'Viikoittainen tilanteesi',
    ],

    'body' => [
        'budget_nudge' => ':category — :spent / :budget käytetty.',
        'receipts_matched' => ':count kuitti täsmäytettiin sähköpostistasi.|:count kuittia täsmäytettiin sähköpostistasi.',
        'import_finished' => ':count tapahtuma tuotu.|:count tapahtumaa tuotu.',
        'drift' => 'Toistuvan veloituksen summa :direction :amount.',
        'forecast' => 'Ennustettu saldosi painuu nollan alle seuraavien 30 päivän aikana.',
        'ics_statement_ready' => 'Lataa se ICS-portaalista ja pudota Beatraxiin, niin tämän kortin kulutus pysyy ajan tasalla.',
        'payment_reminder_hedged' => ':name — odotettavissa noin :day, :amount.',
        'payment_reminder_confident' => ':name — erääntyy :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/kk)',
    ],

    'drift_direction' => [
        'up' => 'nousi',
        'down' => 'laski',
    ],

    'digest' => [
        'nothing_notable' => 'Mikään ei vaadi huomiotasi.',
        'flow' => 'Sisään :in, ulos :out, netto :net.',
        'over_budget' => ':amount yli budjetin toistaiseksi.',
        'payments_due' => ':count maksu erääntyy tällä jaksolla.|:count maksua erääntyy tällä jaksolla.',
        'shortfall' => 'Edessä on kassavaje.',
    ],
];
