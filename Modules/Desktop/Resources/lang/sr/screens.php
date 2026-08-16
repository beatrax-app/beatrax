<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Dobrodošli',
        'heading' => 'Dobrodošli u Beatrax',
        'subtitle' => 'Tvoja isključivo lokalna kontrolna tabla za finansije je spremna. Za početak napravi prvi račun.',
        'get_started' => 'Započni',
    ],

    'setup' => [
        'page_title' => 'Podešavanje…',
        'pending_heading' => 'Podešavanje…',
        'pending_body' => 'Beatrax priprema tvoje podatke. Potrajaće samo trenutak.',
        'failed_body' => 'Podešavanje nije moglo da se završi. Ponovo pokreni Beatrax; ako i dalje ne uspeva, razlog je u logu.',
        'ready_heading' => 'Spremno',
        'ready_body' => 'Podešavanje je završeno. Nastavak…',
    ],

    'staging' => [
        'page_title' => 'Datoteka primljena',
        'heading_prefix' => 'Datoteka primljena: ',
        'button_label' => 'Pokreni uvoz',
        'csv_subtitle' => 'Izvoz iz banke ili PayPala — pokreni uvoz radi pregleda i potvrde.',
        'eml_subtitle' => 'Potvrda iz e-pošte — pokreni uvoz da je priložiš njenoj transakciji.',
        'empty_heading' => 'Nismo mogli da otvorimo tu datoteku',
        'empty_body' => 'Beatrax nije mogao da pročita datoteku koju si otvorio. Pokušaj da je uvezeš sa stranice Uvozi.',
        'open_imports' => 'Otvori Uvoze',
    ],

    'close' => [
        'title' => 'Ostaviti Beatrax pokrenut?',
        'body' => 'Zatvaranje prozora može potpuno da zatvori Beatrax ili da ga ostavi da tiho radi u traci menija kako bi zakazana skeniranja e-pošte nastavila da rade.',
        'button_quit' => 'Izađi iz Beatraxa',
        'button_keep_in_tray' => 'Ostavi da radi u sistemskoj traci',
        'checkbox_remember' => 'Zapamti moj izbor',
    ],
];
