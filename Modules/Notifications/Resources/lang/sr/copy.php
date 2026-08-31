<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Uvoz završen',
        'receipts' => 'Pronađene su nove potvrde',
        'manual_entry' => 'Blagajnička knjiga ažurirana',
        'migration_finished' => 'Migracija završena',
        'drift' => 'Ponavljajuće zaduženje se promenilo',
        'forecast' => 'Sledi manjak sredstava',
        'budget_nudge' => 'Budžet je skoro potrošen',
        'budget_nudge_spent' => 'Budžet je potrošen',
        'budget_nudge_over' => 'Budžet je premašen',
        'savings_prompt' => 'Mesto na kome možeš da uštediš',
        'ics_statement_ready' => 'Novi ICS izvod je spreman',
        'payment_reminder_confident' => 'Plaćanje dospeva :day (:date)',
        'payment_reminder_hedged' => 'Plaćanje dospeva oko :day (:date)',
        'position_digest_daily' => 'Tvoje dnevno stanje',
        'position_digest_weekly' => 'Tvoje nedeljno stanje',
    ],

    'body' => [
        'budget_nudge' => ':category — potrošeno :spent od :budget.',
        'receipts_matched' => 'Uparena je :count potvrda iz tvoje e-pošte.|Uparene su :count potvrde iz tvoje e-pošte.|Upareno je :count potvrda iz tvoje e-pošte.',
        'import_finished' => 'Uvezena je :count transakcija.|Uvezene su :count transakcije.|Uvezeno je :count transakcija.',
        'manual_entry' => 'Ručno je dodat :count unos.|Ručno su dodata :count unosa.|Ručno je dodato :count unosa.',
        'migration_finished' => 'Tvoj budžet je prenet, uključujući :count transakciju.|Tvoj budžet je prenet, uključujući :count transakcije.|Tvoj budžet je prenet, uključujući :count transakcija.',
        'drift' => 'Ponavljajuće zaduženje pomerilo se :direction za :amount.',
        'forecast' => 'Tvoje predviđeno stanje pada ispod nule :date.',
        'forecast_buffer' => 'Tvoje predviđeno stanje pada ispod tvoje rezerve od :buffer :date.',
        'ics_statement_ready' => 'Preuzmi ga sa ICS portala i ubaci u Beatrax kako bi potrošnja ove kartice ostala ažurna.',
        'payment_reminder_hedged' => ':name — očekuje se oko :day (:date), :amount.',
        'payment_reminder_confident' => ':name — dospeva :day (:date), :amount.',
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
