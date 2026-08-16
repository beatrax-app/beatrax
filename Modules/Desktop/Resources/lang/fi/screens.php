<?php

declare(strict_types=1);

return [
    'welcome' => [
        'page_title' => 'Tervetuloa',
        'heading' => 'Tervetuloa Beatraxiin',
        'subtitle' => 'Vain paikallisesti toimiva talousnäkymäsi on valmis. Aloita luomalla ensimmäinen tili.',
        'get_started' => 'Aloita',
    ],

    'setup' => [
        'page_title' => 'Otetaan käyttöön…',
        'pending_heading' => 'Otetaan käyttöön…',
        'pending_body' => 'Beatrax valmistelee tietojasi. Tämä vie vain hetken.',
        'failed_body' => 'Käyttöönottoa ei saatu valmiiksi. Käynnistä Beatrax uudelleen; jos vika toistuu, syy löytyy lokista.',
        'ready_heading' => 'Valmis',
        'ready_body' => 'Käyttöönotto valmis. Jatketaan…',
    ],

    'staging' => [
        'page_title' => 'Tiedosto vastaanotettu',
        'heading_prefix' => 'Tiedosto vastaanotettu: ',
        'button_label' => 'Aloita tuonti',
        'csv_subtitle' => 'Pankin tai PayPalin vienti — aloita tuonti, niin voit esikatsella ja vahvistaa sen.',
        'eml_subtitle' => 'Sähköpostikuitti — aloita tuonti, niin se liitetään omaan tapahtumaansa.',
        'empty_heading' => 'Tiedostoa ei voitu avata',
        'empty_body' => 'Beatrax ei pystynyt lukemaan avaamaasi tiedostoa. Kokeile tuoda se Tuonnit-sivulta.',
        'open_imports' => 'Avaa Tuonnit',
    ],

    'close' => [
        'title' => 'Pidetäänkö Beatrax käynnissä?',
        'body' => 'Ikkunan sulkeminen voi joko lopettaa Beatraxin kokonaan tai jättää sen käymään hiljaa valikkopalkkiin, jolloin ajoitetut sähköpostin skannaukset jatkuvat.',
        'button_quit' => 'Lopeta Beatrax',
        'button_keep_in_tray' => 'Jätä käymään ilmoitusalueelle',
        'checkbox_remember' => 'Muista valintani',
    ],
];
