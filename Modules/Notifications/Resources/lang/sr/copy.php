<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Uvoz završen',
        'receipts' => 'Pronađene su nove potvrde',
        'drift' => 'Ponavljajuće zaduženje se promenilo',
        'forecast' => 'Sledi manjak sredstava',
        'budget_nudge' => 'Budžet je skoro potrošen',
        'savings_prompt' => 'Postoji jeftiniji paket',
        'ics_statement_ready' => 'Novi ICS izvod je spreman',
        'payment_reminder_confident' => 'Plaćanje dospeva :day',
        'payment_reminder_hedged' => 'Plaćanje dospeva oko :day',
        'position_digest_daily' => 'Tvoje dnevno stanje',
        'position_digest_weekly' => 'Tvoje nedeljno stanje',
    ],

    'body' => [
        'budget_nudge' => ':category — potrošeno :spent od :budget.',
        'receipts_matched' => 'Uparena je :count potvrda iz tvoje e-pošte.|Uparene su :count potvrde iz tvoje e-pošte.|Upareno je :count potvrda iz tvoje e-pošte.',
        'import_finished' => 'Uvezena je :count transakcija.|Uvezene su :count transakcije.|Uvezeno je :count transakcija.',
        'drift' => 'Ponavljajuće zaduženje pomerilo se :direction za :delta :currency.',
        'forecast' => 'Tvoje predviđeno stanje pada ispod nule u narednih 30 dana.',
        'ics_statement_ready' => 'Preuzmi ga sa ICS portala i ubaci u Beatrax kako bi potrošnja ove kartice ostala ažurna.',
        'payment_reminder_hedged' => ':name — očekuje se oko :day, :amount.',
        'payment_reminder_confident' => ':name — dospeva :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mes.)',
    ],

    'drift_direction' => [
        'up' => 'naviše',
        'down' => 'naniže',
    ],

    'digest' => [
        'nothing_notable' => 'Ništa ne traži tvoju pažnju.',
        'flow' => 'Priliv :in, odliv :out, neto :net.',
        'over_budget' => 'Dosad :amount iznad budžeta.',
        'payments_due' => ':count plaćanje dospeva u ovom periodu.|:count plaćanja dospevaju u ovom periodu.|:count plaćanja dospeva u ovom periodu.',
        'shortfall' => 'Sledi manjak sredstava.',
    ],
];
