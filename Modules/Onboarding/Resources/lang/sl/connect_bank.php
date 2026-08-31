<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tvoja banka',
    'h1' => 'Prenesi izpisek in ga spusti spodaj',
    'lede' => 'Izberi obliko, ki ti jo je dala banka, in spusti datoteko. CAMT.053 in MT940 zaznamo samodejno.',

    'format_group_aria' => 'Oblika bančnega izpiska',
    'got_it_as' => 'Preneseno kot:',
    'badge_recommended' => 'priporočeno',

    'mini' => [
        'login_label' => 'Prijavi se',
        'login_sub' => 'Spletna stran tvoje banke',
        'statements_label' => 'Odpri izpiske',
        'statements_sub' => 'V meniju tvoje banke',
        'range_label' => 'Izberi obdobje',
        'range_sub' => 'Zadnjih 90 dni',
        'download_label' => 'Prenesi',
    ],

    'csv_picker_aria' => 'Katera banka je izvozila tvoj CSV?',
    'csv_picker_from' => 'Iz:',

    'drop_lead_camt053' => 'Sem spusti svojo datoteko CAMT.053',
    'drop_lead_mt940' => 'Sem spusti svojo datoteko MT940',
    'drop_lead_csv_layout' => 'Sem spusti svoj CSV :layout',
    'drop_lead_pick_bank' => 'Izberi, katera banka je izvozila tvoj CSV — to moramo vedeti, da ga pravilno preberemo.',
    'drop_lead_default' => 'Sem spusti datoteko izpiska',
    'browse_file' => 'ali poišči datoteko',

    'format_help_camt053' => 'CAMT.053 je izpisek v obliki XML — poišči ga v spletni banki med izpiski ali prenosi.',
    'format_help_mt940' => 'MT940 je izpisek v navadnem besedilu, ponujen kot .sta ali .940 poleg prenosov XML in CSV.',
    'format_help_csv' => 'CSV je izvoz za preglednice. Vsaka banka stolpce razporedi drugače, zato izberi ustrezno razporeditev. Če tvoje ni na seznamu, banko prosi za CAMT.053 ali MT940.',

    'account_name_default' => 'Bančni račun',
    'account_name_layout' => 'Račun :layout',

    'file_ready' => '· ✓ pripravljeno',

    'skip' => 'Preskoči ta korak',
    'continue' => 'Nadaljuj →',

    'errors' => [
        'file_required' => 'Najprej spusti datoteko izpiska v okvir.',
        'file_max' => 'Ta datoteka je prevelika. Spusti izpisek, manjši od 10 MB.',
        'file_extensions' => 'Ta datoteka ni videti kot bančni izpisek. Spusti datoteko CAMT.053 XML, CSV ali MT940.',
        'pick_bank' => 'Pred nadaljevanjem izberi, katera banka je izvozila tvoj CSV.',
        'unreadable' => 'Te datoteke ni bilo mogoče prebrati. Celotna napaka je v /dev/logs.',
    ],
];
