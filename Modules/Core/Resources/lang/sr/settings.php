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
        'help' => 'Menja reči na ekranu i način na koji se pišu iznosi. Sistemsko podešavanje prati jezik tvog pregledača ili operativnog sistema, a podrazumevano je engleski.',
    ],

    'timezone' => [
        'heading' => 'Vremenska zona',
        'label' => 'Vremenska zona ove instalacije',
        'help' => 'Određuje na koji dan pada transakcija i u kom okviru se čuvaju vremena. Upareni uređaji dele ovo podešavanje, pa oba čitaju isti dan.',
        'this_machine' => 'Ovaj uređaj (:zone)',
    ],

    'sample_data' => [
        'heading' => 'Probni podaci',
        'help' => 'Puni ovaj račun izmišljenom knjigom — računi, transakcije, budžeti, ciljevi i upozorenja — da ima šta da se pogleda. Dodaje se onome što već postoji i ništa od toga nisu podaci stvarne osobe.',
        'warning' => 'Ovo piše u tvoju sopstvenu knjigu i stiže na tvoje uparene uređaje. Sa ovog ekrana nema poništavanja.',
        'confirm' => 'Dodaj na ovaj račun',
        'cancel' => 'Otkaži',
        'load' => 'Učitaj probne podatke',
        'working' => 'Gradi se probna knjiga. Potrajaće trenutak.',
        'loaded' => 'Probni podaci dodati (:count).',
    ],

    'country' => [
        'heading' => 'Država',
        'label' => 'Tvoja država',
        'help' => 'Određuje po kojoj državi aplikacija prepoznaje poreska pravila, državne institucije i bankarske naknade. Ne menja jezik ni način pisanja iznosa.',
        'choose' => 'Izaberi državu…',
        'switch_note' => 'Promena dodaje nove kategorije — postojeće oznake se nikad ne menjaju.',

        'wording_note' => 'Nazivi poreskih kategorija prikazani su na vašem jeziku; poreska prijava u :country koristi sopstvene izraze.',

        'countries' => [
            'at' => 'Austrija',
            'be' => 'Belgija',
            'bg' => 'Bugarska',
            'ca' => 'Kanada',
            'ch' => 'Švajcarska',
            'cy' => 'Kipar',
            'cz' => 'Češka',
            'de' => 'Nemačka',
            'dk' => 'Danska',
            'ee' => 'Estonija',
            'es' => 'Španija',
            'fi' => 'Finska',
            'fr' => 'Francuska',
            'gb' => 'Ujedinjeno Kraljevstvo',
            'gr' => 'Grčka',
            'hr' => 'Hrvatska',
            'hu' => 'Mađarska',
            'ie' => 'Irska',
            'is' => 'Island',
            'it' => 'Italija',
            'lt' => 'Litvanija',
            'lu' => 'Luksemburg',
            'lv' => 'Letonija',
            'mt' => 'Malta',
            'nl' => 'Holandija',
            'no' => 'Norveška',
            'pl' => 'Poljska',
            'pt' => 'Portugal',
            'ro' => 'Rumunija',
            'se' => 'Švedska',
            'si' => 'Slovenija',
            'sk' => 'Slovačka',
            'us' => 'Sjedinjene Američke Države',
        ],
    ],

    'currency_display' => [
        'heading' => 'Prikaz iznosa',
        'label' => 'Podrazumevani prikaz iznosa',
        'eur_only' => 'Poravnati iznos',
        'original' => 'Izvorni iznos',
        'help' => 'Važi za listu transakcija i za ukupne iznose na kontrolnoj tabli. Prikaz i dalje možeš da promeniš za svaku stranicu, ali samo sa liste transakcija.',
    ],

    'base_currency' => [
        'heading' => 'Osnovna valuta izveštavanja',
        'label' => 'Valuta izveštavanja',
        'help' => 'Svi ukupni iznosi i zbirovi preračunavaju se u ovu valutu. Svaki račun uz to i dalje prikazuje svoju originalnu valutu.',
    ],

    'exchange_rates' => [
        'heading' => 'Kursevi',
        'fetch_online' => 'Preuzmi aktuelne kurseve sa interneta',
        'online_on' => 'Kursevi se dnevno preuzimaju sa ECB-a ili sa Frankfurtera ako ECB nije dostupan. Samo upiti o valutnim parovima — bez ličnih podataka.',
        'last_updated' => 'Poslednje ažuriranje: :date.',
        'online_off' => 'I dalje se koriste već postojeći kursevi, a ugrađeni snimak služi kao rezerva. Nijedan podatak ne napušta ovaj uređaj.',
        'fetch_aria' => 'Preuzmi aktuelne kurseve sa interneta',
        'refreshing' => 'Osvežavanje…',
        'next_refresh' => 'Automatsko osvežavanje: jednom dnevno',
        'refresh_gave_up' => 'Kurseve nije bilo moguće osvežiti. I dalje se koriste kursevi na ovom uređaju.',
        'refresh_now' => 'Osveži sada',
    ],

    'period' => [
        'heading' => 'Period',
        'label' => 'Period počinje na dan',
        'help' => 'Broj od 1 do 28. Većina korisnika ostavlja 1 (kalendarski mesec). Izaberi 25 ako ti plata stiže 25. u mesecu i ako tada za tebe počinje „tvoj mesec”.',

        'move_confirm' => 'Ako period počinje :day. dana, svi iznosi u kovertama se premeštaju i sabiraju tamo gde se dva meseca stapaju u jedan. Vraćanje dana nazad ih više ne razdvaja.',
        'move_cancel' => 'Otkaži',
        'move_apply' => 'Primeni',
    ],

    'recurring' => [
        'heading' => 'Otkrivanje ponavljajućih plaćanja',
        'window_label' => 'Prozor otkrivanja (meseci)',
        'window_help' => 'Koliko meseci istorije pretražiti pri grupisanju transakcija u ponavljajuće obrasce.',
        'income_label' => 'Najmanji prihod (najmanje jedinice)',
        'income_help' => 'Prihodi ispod ovog praga ne grupišu se automatski. Čuva se u najmanjim jedinicama — :minor znači :example. Postavi 0 da isključiš prag.',
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
        'active_phone_html' => 'Fascikla za odlaganje je aktivna. Beatrax u pozadini pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za novim datotekama. Kad će se pozadinsko pretraživanje pokrenuti, odlučuje tvoj telefon — može proći nekoliko minuta ili nekoliko sati.',
        'inactive_phone_html' => 'Kad je uključeno, Beatrax u pozadini pretražuje <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> u potrazi za datotekama <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> i <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> i uvozi ih kroz isti matcher kao i čarobnjak. Kad će se pozadinsko pretraživanje pokrenuti, odlučuje tvoj telefon — može proći nekoliko minuta ili nekoliko sati. Obrađene datoteke premeštaju se u <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> pa se nikad ne uvoze dvaput.',
    ],

    'aliases' => [
        'heading' => 'Alijasi',
        'intro' => 'Pregledaj i izmeni razumljive nazive koje Beatrax koristi za nejasne opise sa izvoda.',
        'manage' => 'Upravljaj alijasima →',
    ],

    'tax_heading' => 'Porez',
    'data_backup_heading' => 'Podaci i rezervna kopija',

    'about_updates' => [
        'heading' => 'O ažuriranjima',
        'body' => 'Nakon instalacije Beatrax se ažurira automatski. Kad instaliraš prvu verziju, buduće verzije stižu putem trake u aplikaciji — ne moraš da se vraćaš na GitHub. Ako neko buduće ažuriranje ne uspe da se primeni, uvek možeš ručno da preuzmeš najnoviji instalacioni program sa stranice izdanja.',
        'body_phone' => 'Ovde se Beatrax ne ažurira sam. Nove verzije mobilne aplikacije stižu preko App Storea ili Google Playa, kao i ostale tvoje aplikacije.',
        'check_label' => 'Automatski proveravaj ažuriranja',
        'check_on' => 'Beatrax pita izvor izdanja da li postoji novija potpisana verzija. Ništa se ne preuzima dok sam ne izabereš instalaciju.',
        'check_off' => 'Provera ažuriranja se ne radi i ništa ne napušta ovaj uređaj. Nove verzije pronalaziš tako što sam otvoriš stranicu izdanja.',
        'open_releases' => 'Otvori stranicu izdanja →',
        'channel_label' => 'Kanal ažuriranja',
        'channel_help' => 'Stabilan je podrazumevani izbor i nudi izdanja koja je neko pregledao. Pregled nudi kandidate za izdanje čim budu objavljeni.',
        'channel_stable' => 'Stabilan',
        'channel_preview' => 'Pregled',
        'channel_preview_note' => 'Verzije za pregled testiraju se manje od stabilnih i mogu sadržati nedovršen rad. Pre bilo kakve instalacije proveravaju se istim potpisom izdavača.',
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
        'period_move_failed' => 'Budžetski mesec nije mogao da se pomeri, pa je ostao gde je bio.',
        'currency_required' => 'Izaberi valutu.',
        'window_months' => 'Izaberi između 2 i 60 meseci.',
        'threshold' => 'Izaberi prag: 1%, 2%, 5%, 10%, 25% ili 50%.',
        'amount' => 'Unesi iznos od :zero naviše.',
        'period_day' => 'Izaberi dan od 1 do 28.',
        'currency_view' => 'Izaberi jednu od dostupnih opcija.',
        'timezone' => 'Izaberi vremensku zonu sa liste.',
    ],
];
