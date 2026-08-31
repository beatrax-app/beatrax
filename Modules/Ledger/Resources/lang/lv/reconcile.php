<?php

declare(strict_types=1);

return [
    'page_title' => 'Saskaņošana',
    'heading' => 'Saskaņošana',
    'intro' => 'Salīdziniet konta izraksta atlikumu ar saviem apstiprinātajiem darījumiem. Kad tie sakrīt, pabeidziet saskaņošanu, lai nofiksētu šīs rindas.',

    'account' => 'Konts',
    'choose_account' => 'Izvēlieties kontu…',
    'statement_date' => 'Konta izraksta datums',
    'statement_balance' => 'Konta izraksta atlikums (:symbol)',
    'balance_help' => 'Aizpildīts no jūsu jaunākā importētā konta izraksta, ja tāds ir pieejams — negatīvs parādam, rediģējams abos gadījumos.',

    'cleared_balance' => 'Apstiprinātais atlikums',
    'statement_target' => 'Konta izraksta mērķis',
    'difference' => 'Starpība',

    'pill' => [
        'choose_account' => 'izvēlieties kontu',
        'choose_date' => 'izvēlieties konta izraksta datumu',
        'enter_balance' => 'ievadiet konta izraksta atlikumu',
        'matched' => 'sakrīt — :amount',
        'discrepancy' => 'neatbilstība — :amount',
        'reconciled_through' => 'saskaņots līdz :date',
    ],

    'mismatch_html' => 'Konta izraksta atlikums vēl nesakrīt ar jūsu apstiprināto atlikumu. Pārslēdziet apstiprinātās rindas <a href=":url" class="underline">darījumu sarakstā</a> vai koriģējiet ievadīto atlikumu, līdz starpība ir nulle — šī plūsma nekad neizveido izlīdzinošu ierakstu.',
    'unreachable_no_baseline_html' => 'Neviena rindu kombinācija nevar samazināt šo starpību līdz nullei. Šim kontam nav reģistrēts sākuma atlikums, tāpēc tā atlikums tiek mērīts no nulles. Importējiet konta izrakstu, ar kuru konts sākas, vai iestatiet sākuma atlikumu sadaļā <a href=":url" class="underline">Iestatījumi</a>.',
    'unreachable' => 'Neviena rindu kombinācija nevar samazināt šo starpību līdz nullei: tā ir ārpus visu šī konta rindu diapazona līdz norādītajam datumam. Pārbaudiet konta izraksta datumu un ievadīto atlikumu.',

    'check' => 'Pārbaudīt',
    'complete' => 'Pabeigt saskaņošanu',
    'complete_unavailable' => 'Līdz šim datumam vairs nav ko fiksēt — atzīmējiet vairāk rindu kā apstiprinātas vai izvēlieties vēlāku konta izraksta datumu.',

    'errors' => [
        'choose_account' => 'Vispirms izvēlieties kontu.',
        'invalid_balance_date' => 'Ievadiet derīgu konta izraksta atlikumu un datumu.',
        'mismatch' => 'Konta izraksta atlikums vēl nesakrīt ar apstiprināto atlikumu — koriģējiet apstiprinātās rindas vai ievadīto atlikumu, līdz starpība ir nulle.',
    ],

    'toast' => [
        'nothing_to_lock' => 'Šim konta izraksta datumam nav ko fiksēt.',
        'complete' => 'Saskaņošana pabeigta — nofiksētas :count rindu.|Saskaņošana pabeigta — nofiksēta :count rinda.|Saskaņošana pabeigta — nofiksētas :count rindas.',
    ],
];
