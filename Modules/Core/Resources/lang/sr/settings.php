<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Prikaz',
        'money' => 'Novac',
        'insights' => 'Uvidi i upozorenja',
        'security' => 'Bezbednost i uređaji',
        'data' => 'Uvozi i podaci',
        'app' => 'Aplikacija',
    ],

    'title' => 'Podešavanja',
    'subtitle' => 'Podešavanja za način na koji se tvoje finansije prikazuju u aplikaciji.',

    'appearance' => [
        'heading' => 'Izgled',
        'theme' => 'Tema',
        'theme_light' => 'Svetla',
        'theme_dark' => 'Tamna',
        'theme_system' => 'Sistemska',
        'theme_help' => 'Sistemska tema prati svetlo ili tamno podešavanje tvog operativnog sistema.',
    ],

    'language' => [
        'apply' => 'Primeni',
        'heading' => 'Jezik',
        'label' => 'Jezik prikaza',

        'system' => 'Sistemski',
        'help' => 'Sistemsko podešavanje prati jezik tvog pregledača ili operativnog sistema, a podrazumevano je engleski.',
    ],

    'currency_display' => [
        'heading' => 'Prikaz valute',
        'label' => 'Podrazumevani prikaz na listi transakcija',
        'eur_only' => 'Samo EUR',
        'original' => 'Originalna valuta',
        'help' => 'Prikaz i dalje možeš da promeniš za svaku stranicu sa liste transakcija.',
    ],

    'base_currency' => [
        'heading' => 'Osnovna valuta izveštavanja',
        'label' => 'Valuta izveštavanja',
        'help' => 'Svi ukupni iznosi i zbirovi preračunavaju se u ovu valutu. Svaki račun uz to i dalje prikazuje svoju originalnu valutu.',
    ],

    'exchange_rates' => [
        'heading' => 'Kursevi',
        'fetch_online' => 'Preuzmi aktuelne kurseve sa interneta',
        'online_on' => 'Kursevi se dnevno preuzimaju sa ECB-a. Samo upiti o valutnim parovima — bez ličnih podataka.',
        'last_updated' => 'Poslednje ažuriranje: :date.',
        'online_off' => 'Koriste se ugrađeni kursevi. Nijedan podatak ne napušta ovaj uređaj.',
        'fetch_aria' => 'Preuzmi aktuelne kurseve sa interneta',
        'refreshing' => 'Osvežavanje…',
        'next_refresh' => 'Sledeće automatsko osvežavanje: svaki dan u 09:00',
        'refresh_gave_up' => 'Kurseve nije bilo moguće osvežiti. I dalje se koriste kursevi na ovom uređaju.',
        'refresh_now' => 'Osveži sada',
    ],

    'period' => [
        'heading' => 'Period',
        'label' => 'Period počinje na dan',
        'help' => 'Broj od 1 do 28. Većina korisnika ostavlja 1 (kalendarski mesec). Izaberi 25 ako ti plata stiže 25. u mesecu i ako tada za tebe počinje „tvoj mesec”.',
    ],

    'recurring' => [
        'heading' => 'Otkrivanje ponavljajućih plaćanja',
        'window_label' => 'Prozor otkrivanja (meseci)',
        'window_help' => 'Koliko meseci istorije pretražiti pri grupisanju transakcija u ponavljajuće obrasce.',
        'income_label' => 'Najmanji prihod (centi)',
        'income_help' => 'Prihodi ispod ovog praga ne grupišu se automatski. Čuva se u centima — 200000 znači €2,000.00. Postavi 0 da isključiš prag.',
    ],

    'drift' => [
        'heading' => 'Upozorenja o odstupanju',
        'label' => 'Podrazumevani prag upozorenja o odstupanju',
        'help' => 'Upozorenja se javljaju kad se najnoviji iznos ponavljajućeg zaduženja razlikuje od prethodnog za više od ovog procenta. Podešavanja pojedinačne serije imaju prednost.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (podrazumevano)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Sačuvaj podešavanja',
    'saved' => 'Sačuvano.',

    'anomaly_heading' => 'Otkrivanje anomalija',
    'notifications_heading' => 'Obaveštenja',

    'forecasting' => [
        'heading' => 'Prognoziranje',
        'intro' => 'Beatrax projektuje tvoje stanje unapred na osnovu trenutnog stanja tvojih računa. Za račune bez stanja sa izvoda (PayPal, stariji uvozi CSV) ovde postavi početno stanje da bi projekcije krenule od poznate tačke.',
        'no_accounts' => 'Još nema računa — uvezi izvod da dodaš račun.',
    ],

    'auto_import' => [
        'heading' => 'Automatski uvoz',
        'label' => 'Automatski uvoz iz fascikle za odlaganje',

        'active_html' => 'Fascikla za odlaganje je aktivna. Beatrax svakih 5 minuta pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za novim datotekama.',
        'inactive_html' => 'Kad je uključeno, Beatrax svakih 5 minuta pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za datotekama <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> i <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> i uvozi ih kroz isti matcher kao i čarobnjak. Obrađene datoteke premeštaju se u <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> pa se nikad ne uvoze dvaput.',
    ],

    'aliases' => [
        'heading' => 'Alijasi',
        'intro' => 'Pregledaj i izmeni razumljive nazive koje Beatrax koristi za nejasne opise sa izvoda.',
        'manage' => 'Upravljaj alijasima →',
    ],

    'tax_heading' => 'Porez',
    'shared_merchant_heading' => 'Zajednička lista trgovaca',
    'data_backup_heading' => 'Podaci i rezervna kopija',
    'install_heading' => 'Instalacija',

    'about_updates' => [
        'heading' => 'O ažuriranjima',
        'body' => 'Nakon instalacije Beatrax se ažurira automatski. Kad instaliraš prvu verziju, buduće verzije stižu putem trake u aplikaciji — ne moraš da se vraćaš na GitHub. Ako neko buduće ažuriranje ne uspe da se primeni, uvek možeš ručno da preuzmeš najnoviji instalacioni program sa stranice izdanja.',
        'open_releases' => 'Otvori stranicu izdanja →',
    ],

    'privacy' => [
        'heading' => 'Politika privatnosti',
        'body' => 'Beatrax drži tvoje finansije na tvojim sopstvenim uređajima. Politika objašnjava šta to znači, šta šalju opcione onlajn funkcije i kako da ukloniš svoje podatke.',
        'open' => 'Pročitaj politiku privatnosti →',
        'url_hint' => 'Ako se link ne otvori, poseti:',
    ],

    'first_run_tour' => [
        'heading' => 'Vodič za prvo pokretanje',
        'body' => 'Ponovo pokreni čarobnjak za podešavanje ako želiš još jednom da prođeš uvodni tok.',
        'run_again' => 'Ponovo pokreni čarobnjak za podešavanje',
    ],

    'developer' => [
        'heading' => 'Programer',
        'label' => 'Razvojna konzola u aplikaciji',
        'help' => 'Prikazuje razvojnu konzolu na /dev. Prekidač Napredno se resetuje pri svakoj prijavi.',
        'aria' => 'Razvojni režim',
    ],

    'errors' => [
        'currency_required' => 'Izaberi valutu.',
        'window_months' => 'Izaberi između 2 i 60 meseci.',
        'threshold' => 'Izaberi prag: 1%, 2%, 5%, 10%, 25% ili 50%.',
        'amount' => 'Unesi iznos od €0 naviše.',
        'period_day' => 'Izaberi dan od 1 do 28.',
        'currency_view' => 'Izaberi jednu od dostupnih opcija.',
    ],
];
