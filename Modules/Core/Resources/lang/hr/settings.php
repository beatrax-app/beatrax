<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Prikaz',
        'money' => 'Novac',
        'insights' => 'Uvidi i upozorenja',
        'security' => 'Sigurnost i uređaji',
        'data' => 'Uvozi i podaci',
        'app' => 'Aplikacija',
    ],

    'title' => 'Postavke',
    'subtitle' => 'Postavke za način na koji se tvoje financije prikazuju u aplikaciji.',

    'appearance' => [
        'heading' => 'Izgled',
        'theme' => 'Tema',
        'theme_light' => 'Svijetla',
        'theme_dark' => 'Tamna',
        'theme_system' => 'Sustavska',
        'theme_help' => 'Sustavska tema prati svijetlu ili tamnu postavku tvog operativnog sustava.',
    ],

    'language' => [
        'apply' => 'Primijeni',
        'heading' => 'Jezik',
        'label' => 'Jezik prikaza',

        'system' => 'Sustavski',
        'help' => 'Mijenja riječi na zaslonu i način na koji se pišu iznosi. Sustavska postavka prati jezik tvog preglednika ili operativnog sustava, a zadano je engleski.',
    ],

    'country' => [
        'heading' => 'Država',
        'label' => 'Tvoja država',
        'help' => 'Određuje čije porezne propise, državne institucije i bankovne naknade aplikacija prepoznaje. Jezik i način pisanja iznosa se time ne mijenjaju.',
        'choose' => 'Odaberi državu…',
        'switch_note' => 'Promjena dodaje nove kategorije — postojeće oznake nikad se ne mijenjaju.',

        'wording_note' => 'Nazivi poreznih kategorija dolaze iz porezne prijave koja se koristi u :country, pa ostaju na riječima te zemlje na svim jezicima aplikacije.',

        'countries' => [
            'at' => 'Austrija',
            'be' => 'Belgija',
            'bg' => 'Bugarska',
            'ca' => 'Kanada',
            'ch' => 'Švicarska',
            'cy' => 'Cipar',
            'cz' => 'Češka',
            'de' => 'Njemačka',
            'dk' => 'Danska',
            'ee' => 'Estonija',
            'es' => 'Španjolska',
            'fi' => 'Finska',
            'fr' => 'Francuska',
            'gb' => 'Ujedinjeno Kraljevstvo',
            'gr' => 'Grčka',
            'hr' => 'Hrvatska',
            'hu' => 'Mađarska',
            'ie' => 'Irska',
            'is' => 'Island',
            'it' => 'Italija',
            'lt' => 'Litva',
            'lu' => 'Luksemburg',
            'lv' => 'Latvija',
            'mt' => 'Malta',
            'nl' => 'Nizozemska',
            'no' => 'Norveška',
            'pl' => 'Poljska',
            'pt' => 'Portugal',
            'ro' => 'Rumunjska',
            'se' => 'Švedska',
            'si' => 'Slovenija',
            'sk' => 'Slovačka',
            'us' => 'Sjedinjene Američke Države',
        ],
    ],

    'currency_display' => [
        'heading' => 'Prikaz iznosa',
        'label' => 'Zadani prikaz na popisu transakcija',
        'eur_only' => 'Podmireni iznos',
        'original' => 'Izvorni iznos',
        'help' => 'Prikaz i dalje možeš promijeniti za svaku stranicu s popisa transakcija.',
    ],

    'base_currency' => [
        'heading' => 'Osnovna valuta izvješćivanja',
        'label' => 'Valuta izvješćivanja',
        'help' => 'Svi se ukupni iznosi i zbrojevi preračunavaju u ovu valutu. Svaki račun uz to i dalje prikazuje svoju izvornu valutu.',
    ],

    'exchange_rates' => [
        'heading' => 'Tečajevi',
        'fetch_online' => 'Dohvati aktualne tečajeve s interneta',
        'online_on' => 'Tečajevi se dnevno dohvaćaju s ECB-a. Samo upiti o valutnim parovima — bez osobnih podataka.',
        'last_updated' => 'Zadnje ažuriranje: :date.',
        'online_off' => 'Koriste se ugrađeni tečajevi. Nijedan podatak ne napušta ovaj uređaj.',
        'fetch_aria' => 'Dohvati aktualne tečajeve s interneta',
        'refreshing' => 'Osvježavanje…',
        'next_refresh' => 'Automatsko osvježavanje: jednom dnevno',
        'refresh_gave_up' => 'Tečajeve nije bilo moguće osvježiti. I dalje se koriste tečajevi na ovom uređaju.',
        'refresh_now' => 'Osvježi sada',
    ],

    'period' => [
        'heading' => 'Razdoblje',
        'label' => 'Razdoblje počinje na dan',
        'help' => 'Broj od 1 do 28. Većina korisnika ostavlja 1 (kalendarski mjesec). Odaberi 25 ako ti plaća stiže 25. u mjesecu i ako tada za tebe počinje „tvoj mjesec”.',

        'move_confirm' => 'Ako razdoblje počinje na dan :day, svi iznosi u omotnicama premještaju se i zbrajaju ondje gdje se dva mjeseca stapaju u jedan. Vraćanje dana natrag više ih ne razdvaja.',
        'move_cancel' => 'Odustani',
        'move_apply' => 'Primijeni',
    ],

    'recurring' => [
        'heading' => 'Otkrivanje ponavljajućih plaćanja',
        'window_label' => 'Prozor otkrivanja (mjeseci)',
        'window_help' => 'Koliko mjeseci povijesti pretražiti pri grupiranju transakcija u ponavljajuće obrasce.',
        'income_label' => 'Najmanji prihod (najmanje jedinice)',
        'income_help' => 'Prihodi ispod ovog praga ne grupiraju se automatski. Sprema se u najmanjim jedinicama — :minor znači :example. Postavi 0 za isključivanje praga.',
    ],

    'drift' => [
        'heading' => 'Upozorenja o odstupanju',
        'label' => 'Zadani prag upozorenja o odstupanju',
        'help' => 'Upozorenja se javljaju kad se najnoviji iznos ponavljajućeg terećenja razlikuje od prethodnog za više od ovog postotka. Postavke pojedinačne serije imaju prednost.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (zadano)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Spremi postavke',
    'saved' => 'Spremljeno.',

    'anomaly_heading' => 'Otkrivanje anomalija',
    'notifications_heading' => 'Obavijesti',

    'forecasting' => [
        'heading' => 'Prognoziranje',
        'intro' => 'Beatrax projicira tvoje stanje unaprijed na temelju trenutnog stanja tvojih računa. Za račune bez stanja s izvoda (PayPal, stariji uvozi CSV) ovdje postavi početno stanje kako bi projekcije krenule od poznate točke.',
        'no_accounts' => 'Još nema računa — uvezi izvod da dodaš račun.',
    ],

    'auto_import' => [
        'heading' => 'Automatski uvoz',
        'label' => 'Automatski uvoz iz mape za odlaganje',

        'active_html' => 'Mapa za odlaganje je aktivna. Beatrax svakih 5 minuta pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za novim datotekama.',
        'inactive_html' => 'Kad je uključeno, Beatrax svakih 5 minuta pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za datotekama <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> i <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> te ih uvozi kroz isti matcher kao i čarobnjak. Obrađene datoteke premještaju se u <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> pa se nikad ne uvoze dvaput.',
        'active_phone_html' => 'Mapa za odlaganje je aktivna. Beatrax u pozadini pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za novim datotekama. Kad će se pozadinsko pretraživanje pokrenuti, odlučuje tvoj telefon — može proći nekoliko minuta ili nekoliko sati.',
        'inactive_phone_html' => 'Kad je uključeno, Beatrax u pozadini pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za datotekama <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> i <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> te ih uvozi kroz isti matcher kao i čarobnjak. Kad će se pozadinsko pretraživanje pokrenuti, odlučuje tvoj telefon — može proći nekoliko minuta ili nekoliko sati. Obrađene datoteke premještaju se u <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> pa se nikad ne uvoze dvaput.',
    ],

    'aliases' => [
        'heading' => 'Aliasi',
        'intro' => 'Pregledaj i uredi razumljive nazive koje Beatrax koristi za nejasne opise s izvoda.',
        'manage' => 'Upravljaj aliasima →',
    ],

    'tax_heading' => 'Porez',
    'data_backup_heading' => 'Podaci i sigurnosna kopija',

    'about_updates' => [
        'heading' => 'O ažuriranjima',
        'body' => 'Nakon instalacije Beatrax se ažurira automatski. Kad instaliraš prvu verziju, buduće verzije stižu putem trake u aplikaciji — ne moraš se vraćati na GitHub. Ako se neko buduće ažuriranje ne uspije primijeniti, uvijek možeš ručno preuzeti najnoviji instalacijski program sa stranice izdanja.',
        'body_phone' => 'Ovdje se Beatrax ne ažurira sam. Nove verzije mobilne aplikacije stižu preko App Storea ili Google Playa, kao i ostale tvoje aplikacije. Na stranici izdanja piše što je u svakoj promijenjeno.',
        'open_releases' => 'Otvori stranicu izdanja →',
    ],

    'privacy' => [
        'heading' => 'Pravila privatnosti',
        'body' => 'Beatrax drži tvoje financije na tvojim vlastitim uređajima. Pravila objašnjavaju što to znači, što šalju neobavezne mrežne funkcije i kako ukloniti svoje podatke.',
        'open' => 'Pročitaj pravila privatnosti →',
        'url_hint' => 'Ako se poveznica ne otvori, posjeti:',
    ],

    'first_run_tour' => [
        'heading' => 'Vodič za prvo pokretanje',
        'body' => 'Ponovno pokreni čarobnjak za postavljanje ako želiš još jednom proći uvodni tijek.',
        'run_again' => 'Ponovno pokreni čarobnjak za postavljanje',
    ],

    'developer' => [
        'heading' => 'Programer',
        'label' => 'Razvojna konzola u aplikaciji',
        'help' => 'Prikazuje razvojnu konzolu na /dev. Prekidač Napredno poništava se pri svakoj prijavi.',
        'aria' => 'Razvojni način',
    ],

    'errors' => [
        'period_move_failed' => 'Proračunski mjesec nije se mogao pomaknuti, pa je ostao gdje je bio.',
        'currency_required' => 'Odaberi valutu.',
        'window_months' => 'Odaberi između 2 i 60 mjeseci.',
        'threshold' => 'Odaberi prag: 1 %, 2 %, 5 %, 10 %, 25 % ili 50 %.',
        'amount' => 'Unesi iznos od :zero naviše.',
        'period_day' => 'Odaberi dan od 1 do 28.',
        'currency_view' => 'Odaberi jednu od dostupnih opcija.',
    ],
];
