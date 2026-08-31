<?php

declare(strict_types=1);

return [
    'page_title' => 'Usklađivanje',
    'heading' => 'Usklađivanje',
    'intro' => 'Potvrdi stanje s izvoda računa u odnosu na svoje proknjižene transakcije. Kada se podudaraju, dovrši usklađivanje da zaključaš te retke.',

    'account' => 'Račun',
    'choose_account' => 'Odaberi račun…',
    'statement_date' => 'Datum izvoda',
    'statement_balance' => 'Stanje s izvoda (:symbol)',
    'balance_help' => 'Unaprijed popunjeno iz tvog zadnjeg uvezenog izvoda kada je dostupno — negativno za dugovanja, u oba slučaja izmjenjivo.',

    'cleared_balance' => 'Proknjiženo stanje',
    'statement_target' => 'Cilj s izvoda',
    'difference' => 'Razlika',

    'pill' => [
        'choose_account' => 'odaberi račun',
        'choose_date' => 'odaberi datum izvoda',
        'enter_balance' => 'unesi stanje s izvoda',
        'matched' => 'podudara se — :amount',
        'discrepancy' => 'odstupanje — :amount',
        'reconciled_through' => 'usklađeno do :date',
    ],

    'mismatch_html' => 'Stanje s izvoda još se ne podudara s tvojim proknjiženim stanjem. Uključi ili isključi proknjižene retke na <a href=":url" class="underline">popisu transakcija</a> ili prilagodi uneseno stanje dok razlika ne dosegne nulu — ovaj tijek nikada ne stvara stavku za izravnanje.',
    'unreachable_no_baseline_html' => 'Nijedna kombinacija redaka ne može ovu razliku svesti na nulu. Ovaj račun nema zabilježeno početno stanje, pa se njegovo stanje mjeri od nule. Uvezi izvod s kojim se račun otvara ili postavi početno stanje u <a href=":url" class="underline">Postavkama</a>.',
    'unreachable' => 'Nijedna kombinacija redaka ne može ovu razliku svesti na nulu: nalazi se izvan raspona svih redaka na ovom računu do zadanog datuma. Provjeri datum izvoda i uneseno stanje.',

    'check' => 'Provjeri',
    'complete' => 'Dovrši usklađivanje',
    'complete_unavailable' => 'Do ovog datuma više nema ništa za zaključavanje — označi još redaka kao proknjižene ili odaberi kasniji datum izvoda.',

    'errors' => [
        'choose_account' => 'Prvo odaberi račun.',
        'invalid_balance_date' => 'Unesi valjano stanje s izvoda i datum.',
        'mismatch' => 'Stanje s izvoda još se ne podudara s proknjiženim stanjem — prilagodi proknjižene retke ili uneseno stanje dok razlika ne bude nula.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Nema ništa za zaključavanje za ovaj datum izvoda.',
        'complete' => 'Usklađivanje dovršeno — zaključan :count redak.|Usklađivanje dovršeno — zaključana :count retka.|Usklađivanje dovršeno — zaključano :count redaka.',
    ],
];
