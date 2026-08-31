<?php

declare(strict_types=1);

return [
    'page_title' => 'Uskladitev',
    'heading' => 'Uskladitev',
    'intro' => 'Potrdi stanje z izpiska računa glede na svoje knjižene transakcije. Ko se ujemata, dokončaj uskladitev in tako zakleni te vrstice.',

    'account' => 'Račun',
    'choose_account' => 'Izberi račun…',
    'statement_date' => 'Datum izpiska',
    'statement_balance' => 'Stanje z izpiska (:symbol)',
    'balance_help' => 'Vnaprej izpolnjeno iz tvojega zadnjega uvoženega izpiska, kadar je na voljo — negativno za dolgovani znesek, v obeh primerih uredljivo.',

    'cleared_balance' => 'Knjiženo stanje',
    'statement_target' => 'Cilj z izpiska',
    'difference' => 'Razlika',

    'pill' => [
        'choose_account' => 'izberi račun',
        'choose_date' => 'izberi datum izpiska',
        'enter_balance' => 'vnesi stanje z izpiska',
        'matched' => 'ujema se — :amount',
        'discrepancy' => 'odstopanje — :amount',
        'reconciled_through' => 'usklajeno do :date',
    ],

    'mismatch_html' => 'Stanje z izpiska se še ne ujema s tvojim knjiženim stanjem. Preklopi knjižene vrstice na <a href=":url" class="underline">seznamu transakcij</a> ali prilagodi vneseno stanje, dokler razlika ne doseže nič — ta potek nikoli ne ustvari izravnalne postavke.',
    'unreachable_no_baseline_html' => 'Nobena kombinacija vrstic ne more te razlike spraviti na nič. Ta račun nima zabeleženega začetnega stanja, zato se njegovo stanje meri od nič. Uvozi izpisek, s katerim se račun odpre, ali nastavi začetno stanje v <a href=":url" class="underline">Nastavitvah</a>.',
    'unreachable' => 'Nobena kombinacija vrstic ne more te razlike spraviti na nič: leži zunaj obsega vseh vrstic na tem računu do navedenega datuma. Preveri datum izpiska in vneseno stanje.',

    'check' => 'Preveri',
    'complete' => 'Dokončaj uskladitev',
    'complete_unavailable' => 'Do tega datuma ni več ničesar za zakleniti — označi več vrstic kot knjižene ali izberi poznejši datum izpiska.',

    'errors' => [
        'choose_account' => 'Najprej izberi račun.',
        'invalid_balance_date' => 'Vnesi veljavno stanje z izpiska in datum.',
        'mismatch' => 'Stanje z izpiska se še ne ujema s knjiženim stanjem — prilagodi knjižene vrstice ali vneseno stanje, dokler razlika ni nič.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Za ta datum izpiska ni ničesar za zakleniti.',
        'complete' => 'Uskladitev končana — zaklenjena :count vrstica.|Uskladitev končana — zaklenjeni :count vrstici.|Uskladitev končana — zaklenjene :count vrstice.|Uskladitev končana — zaklenjenih :count vrstic.',
    ],
];
