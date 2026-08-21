<?php

declare(strict_types=1);

return [
    'page_title' => 'Uskladitev',
    'heading' => 'Uskladitev',
    'intro' => 'Potrdi stanje z izpiska računa glede na svoje knjižene transakcije. Ko se ujemata, dokončaj uskladitev in tako zakleni te vrstice.',

    'account' => 'Račun',
    'choose_account' => 'Izberi račun…',
    'statement_date' => 'Datum izpiska',
    'statement_balance' => 'Stanje z izpiska (€)',
    'balance_help' => 'Vnaprej izpolnjeno iz tvojega zadnjega uvoženega izpiska, kadar je na voljo — negativno za dolgovani znesek, v obeh primerih uredljivo.',

    'cleared_balance' => 'Knjiženo stanje',
    'statement_target' => 'Cilj z izpiska',
    'difference' => 'Razlika',

    'pill' => [
        'choose_account' => 'izberi račun',
        'enter_balance' => 'vnesi stanje z izpiska',
        'matched' => 'ujema se — :amount',
        'discrepancy' => 'odstopanje — :amount',
    ],

    'mismatch_html' => 'Stanje z izpiska se še ne ujema s tvojim knjiženim stanjem. Preklopi knjižene vrstice na <a href=":url" class="underline">seznamu transakcij</a> ali prilagodi vneseno stanje, dokler razlika ne doseže nič — ta potek nikoli ne ustvari izravnalne postavke.',

    'check' => 'Preveri',
    'complete' => 'Dokončaj uskladitev',

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
