<?php

declare(strict_types=1);

return [
    'page_title' => 'Usaglašavanje',
    'heading' => 'Usaglašavanje',
    'intro' => 'Potvrdi stanje sa izvoda računa u odnosu na svoje proknjižene transakcije. Kada se poklapaju, dovrši usaglašavanje da zaključaš te redove.',

    'account' => 'Račun',
    'choose_account' => 'Izaberi račun…',
    'statement_date' => 'Datum izvoda',
    'statement_balance' => 'Stanje sa izvoda (:symbol)',
    'balance_help' => 'Unapred popunjeno iz tvog poslednjeg uvezenog izvoda kada je dostupno — negativno za dugovanja, u oba slučaja izmenjivo.',

    'cleared_balance' => 'Proknjiženo stanje',
    'statement_target' => 'Cilj sa izvoda',
    'difference' => 'Razlika',

    'pill' => [
        'choose_account' => 'izaberi račun',
        'choose_date' => 'izaberi datum izvoda',
        'enter_balance' => 'unesi stanje sa izvoda',
        'matched' => 'poklapa se — :amount',
        'discrepancy' => 'odstupanje — :amount',
        'reconciled_through' => 'usaglašeno do :date',
    ],

    'mismatch_html' => 'Stanje sa izvoda se još ne poklapa sa tvojim proknjiženim stanjem. Uključi ili isključi proknjižene redove na <a href=":url" class="underline">listi transakcija</a> ili prilagodi uneseno stanje dok razlika ne dođe do nule — ovaj tok nikada ne pravi stavku za izravnanje.',
    'unreachable_no_baseline_html' => 'Nijedna kombinacija redova ne može ovu razliku svesti na nulu. Ovaj račun nema zabeleženo početno stanje, pa se njegovo stanje meri od nule. Uvezi izvod sa kojim se račun otvara ili postavi početno stanje u <a href=":url" class="underline">Podešavanjima</a>.',
    'unreachable' => 'Nijedna kombinacija redova ne može ovu razliku svesti na nulu: nalazi se izvan raspona svih redova na ovom računu do zadatog datuma. Proveri datum izvoda i uneseno stanje.',

    'check' => 'Proveri',
    'complete' => 'Dovrši usaglašavanje',
    'complete_unavailable' => 'Do ovog datuma više nema ništa za zaključavanje — označi još redova kao proknjižene ili izaberi kasniji datum izvoda.',

    'errors' => [
        'choose_account' => 'Prvo izaberi račun.',
        'invalid_balance_date' => 'Unesi važeće stanje sa izvoda i datum.',
        'mismatch' => 'Stanje sa izvoda se još ne poklapa sa proknjiženim stanjem — prilagodi proknjižene redove ili uneseno stanje dok razlika ne bude nula.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Nema ništa za zaključavanje za ovaj datum izvoda.',
        'complete' => 'Usaglašavanje dovršeno — zaključan :count red.|Usaglašavanje dovršeno — zaključana :count reda.|Usaglašavanje dovršeno — zaključano :count redova.',
    ],
];
