<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Uvoz dovršen',
        'receipts' => 'Pronađene su nove potvrde',
        'drift' => 'Ponavljajuće terećenje se promijenilo',
        'forecast' => 'Slijedi manjak sredstava',
        'budget_nudge' => 'Proračun je gotovo potrošen',
        'savings_prompt' => 'Postoji jeftiniji paket',
        'ics_statement_ready' => 'Novi ICS izvod je spreman',
        'payment_reminder_confident' => 'Plaćanje dospijeva :day',
        'payment_reminder_hedged' => 'Plaćanje dospijeva oko :day',
        'position_digest_daily' => 'Tvoje dnevno stanje',
        'position_digest_weekly' => 'Tvoje tjedno stanje',
    ],

    'body' => [
        'budget_nudge' => ':category — potrošeno :spent od :budget.',
        'receipts_matched' => 'Uparena je :count potvrda iz tvoje e-pošte.|Uparene su :count potvrde iz tvoje e-pošte.|Upareno je :count potvrda iz tvoje e-pošte.',
        'import_finished' => 'Uvezena je :count transakcija.|Uvezene su :count transakcije.|Uvezeno je :count transakcija.',
        'drift' => 'Ponavljajuće terećenje pomaknulo se :direction za :delta :currency.',
        'forecast' => 'Tvoje predviđeno stanje pada ispod nule unutar sljedećih 30 dana.',
        'ics_statement_ready' => 'Preuzmi ga s ICS portala i ubaci u Beatrax kako bi potrošnja ove kartice ostala ažurna.',
        'payment_reminder_hedged' => ':name — očekuje se oko :day, :amount.',
        'payment_reminder_confident' => ':name — dospijeva :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/mj.)',
    ],

    'drift_direction' => [
        'up' => 'prema gore',
        'down' => 'prema dolje',
    ],

    'digest' => [
        'nothing_notable' => 'Ništa ne traži tvoju pažnju.',
        'flow' => 'Priljev :in, odljev :out, neto :net.',
        'over_budget' => 'Dosad :amount iznad proračuna.',
        'payments_due' => ':count plaćanje dospijeva u ovom razdoblju.|:count plaćanja dospijevaju u ovom razdoblju.|:count plaćanja dospijeva u ovom razdoblju.',
        'shortfall' => 'Slijedi manjak sredstava.',
    ],
];
