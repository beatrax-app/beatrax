<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Dobrodošli',
        'heading' => 'Dobrodošli u Beatrax',
        'subtitle' => 'Tvoja isključivo lokalna nadzorna ploča za financije je spremna. Za početak stvori prvi račun.',
        'get_started' => 'Započni',
    ],

    'setup' => [
        'page_title' => 'Postavljanje…',
        'pending_heading' => 'Postavljanje…',
        'pending_body' => 'Beatrax priprema tvoje podatke. To traje samo trenutak.',
        'failed_body' => 'Postavljanje nije moglo završiti. Ponovno pokreni Beatrax; ako i dalje ne uspijeva, razlog je u zapisniku.',
        'ready_heading' => 'Spremno',
        'ready_body' => 'Postavljanje je dovršeno. Nastavak…',
    ],

    'staging' => [
        'page_title' => 'Datoteka primljena',
        'heading_prefix' => 'Datoteka primljena: ',
        'button_label' => 'Pokreni uvoz',
        'csv_subtitle' => 'Izvoz iz banke ili PayPala — pokreni uvoz za pregled i potvrdu.',
        'eml_subtitle' => 'Potvrda iz e-pošte — pokreni uvoz da je priložiš njezinoj transakciji.',
        'empty_heading' => 'Nismo mogli otvoriti tu datoteku',
        'empty_body' => 'Beatrax ne može pročitati datoteku koju si otvorio. Pokušaj je uvesti sa stranice Uvozi.',
        'open_imports' => 'Otvori Uvoze',
    ],

    'close' => [
        'title' => 'Ostaviti Beatrax pokrenutim?',
        'body' => 'Zatvaranje prozora može potpuno zatvoriti Beatrax ili ga ostaviti da tiho radi u traci izbornika kako bi zakazana skeniranja e-pošte nastavila raditi.',
        'button_quit' => 'Izađi iz Beatraxa',
        'button_keep_in_tray' => 'Ostavi da radi u traci sustava',
        'checkbox_remember' => 'Zapamti moj izbor',
    ],
];
