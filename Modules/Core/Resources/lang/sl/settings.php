<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Prikaz',
        'money' => 'Denar',
        'insights' => 'Vpogledi in opozorila',
        'security' => 'Varnost in naprave',
        'data' => 'Uvozi in podatki',
        'app' => 'Aplikacija',
    ],

    'title' => 'Nastavitve',
    'subtitle' => 'Nastavitve za prikaz tvojih financ v aplikaciji.',

    'appearance' => [
        'heading' => 'Videz',
        'theme' => 'Tema',
        'theme_light' => 'Svetla',
        'theme_dark' => 'Temna',
        'theme_system' => 'Sistemska',
        'theme_help' => 'Sistemska tema sledi svetli ali temni nastavitvi tvojega operacijskega sistema.',
    ],

    'language' => [
        'apply' => 'Uporabi',
        'heading' => 'Jezik',
        'label' => 'Jezik prikaza',

        'system' => 'Sistemski',
        'help' => 'Spremeni besede na zaslonu in način zapisa zneskov. Sistemska nastavitev sledi jeziku tvojega brskalnika ali operacijskega sistema, privzeto pa je angleščina.',
    ],

    'country' => [
        'heading' => 'Država',
        'label' => 'Tvoja država',
        'help' => 'Določa, po kateri državi aplikacija prepoznava davčna pravila, državne ustanove in bančne stroške. Jezika in zapisa zneskov ne spremeni.',
        'choose' => 'Izberi državo…',
        'switch_note' => 'Zamenjava doda nove kategorije — obstoječe oznake se nikoli ne spremenijo.',

        'wording_note' => 'Imena davčnih kategorij izhajajo iz davčne napovedi, ki se uporablja v :country, zato ostanejo v besedah te države v vseh jezikih aplikacije.',

        'countries' => [
            'at' => 'Avstrija',
            'be' => 'Belgija',
            'bg' => 'Bolgarija',
            'ca' => 'Kanada',
            'ch' => 'Švica',
            'cy' => 'Ciper',
            'cz' => 'Češka',
            'de' => 'Nemčija',
            'dk' => 'Danska',
            'ee' => 'Estonija',
            'es' => 'Španija',
            'fi' => 'Finska',
            'fr' => 'Francija',
            'gb' => 'Združeno kraljestvo',
            'gr' => 'Grčija',
            'hr' => 'Hrvaška',
            'hu' => 'Madžarska',
            'ie' => 'Irska',
            'is' => 'Islandija',
            'it' => 'Italija',
            'lt' => 'Litva',
            'lu' => 'Luksemburg',
            'lv' => 'Latvija',
            'mt' => 'Malta',
            'nl' => 'Nizozemska',
            'no' => 'Norveška',
            'pl' => 'Poljska',
            'pt' => 'Portugalska',
            'ro' => 'Romunija',
            'se' => 'Švedska',
            'si' => 'Slovenija',
            'sk' => 'Slovaška',
            'us' => 'Združene države Amerike',
        ],
    ],

    'currency_display' => [
        'heading' => 'Prikaz zneska',
        'label' => 'Privzeti pogled na seznamu transakcij',
        'eur_only' => 'Poravnani znesek',
        'original' => 'Izvirni znesek',
        'help' => 'Pogled lahko še vedno preklopiš za vsako stran s seznama transakcij.',
    ],

    'base_currency' => [
        'heading' => 'Osnovna valuta poročanja',
        'label' => 'Valuta poročanja',
        'help' => 'Vsi skupni zneski in seštevki se preračunajo v to valuto. Vsak račun ob tem še vedno prikazuje svojo izvirno valuto.',
    ],

    'exchange_rates' => [
        'heading' => 'Menjalni tečaji',
        'fetch_online' => 'Pridobi aktualne tečaje iz spleta',
        'online_on' => 'Tečaji se dnevno pridobivajo od ECB. Samo poizvedbe o valutnih parih — brez osebnih podatkov.',
        'last_updated' => 'Zadnja posodobitev: :date.',
        'online_off' => 'Uporabljajo se priloženi tečaji. Noben podatek ne zapusti te naprave.',
        'fetch_aria' => 'Pridobi aktualne menjalne tečaje iz spleta',
        'refreshing' => 'Osveževanje…',
        'next_refresh' => 'Samodejno osveževanje: enkrat na dan',
        'refresh_gave_up' => 'Tečajev ni bilo mogoče osvežiti. Še naprej se uporabljajo tečaji v tej napravi.',
        'refresh_now' => 'Osveži zdaj',
    ],

    'period' => [
        'heading' => 'Obdobje',
        'label' => 'Obdobje se začne na dan',
        'help' => 'Številka od 1 do 28. Večina uporabnikov pusti 1 (koledarski mesec). Izberi 25, če ti plača prispe 25. v mesecu in se takrat zate začne „tvoj mesec“.',

        'move_confirm' => 'Če se obdobje začne na dan :day, se vsi zneski v ovojnicah prerazporedijo in seštejejo tam, kjer se dva meseca zlijeta v enega. Vrnitev dneva nazaj jih ne razdeli več.',
        'move_cancel' => 'Prekliči',
        'move_apply' => 'Uporabi',
    ],

    'recurring' => [
        'heading' => 'Zaznavanje ponavljajočih se plačil',
        'window_label' => 'Okno zaznavanja (meseci)',
        'window_help' => 'Koliko mesecev zgodovine naj se pregleda pri združevanju transakcij v ponavljajoče se vzorce.',
        'income_label' => 'Najnižji prihodek (najmanjše enote)',
        'income_help' => 'Prihodki pod tem pragom se ne združujejo samodejno. Shranjeno v najmanjših enotah — :minor pomeni :example. Nastavi 0, da prag izklopiš.',
    ],

    'drift' => [
        'heading' => 'Opozorila o odstopanju',
        'label' => 'Privzeti prag opozorila o odstopanju',
        'help' => 'Opozorila se sprožijo, ko se najnovejši znesek ponavljajoče se bremenitve razlikuje od prejšnjega za več kot ta odstotek. Nastavitve posamezne serije imajo prednost.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (privzeto)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Shrani nastavitve',
    'saved' => 'Shranjeno.',

    'anomaly_heading' => 'Zaznavanje anomalij',
    'notifications_heading' => 'Obvestila',

    'forecasting' => [
        'heading' => 'Napovedovanje',
        'intro' => 'Beatrax napove tvoje stanje naprej iz trenutnega stanja tvojih računov. Za račune brez stanj z izpiskov (PayPal, starejši uvozi CSV) tukaj nastavi začetno stanje, da se napovedi začnejo iz znane točke.',
        'no_accounts' => 'Računov še ni — uvozi izpisek in dodaj račun.',
    ],

    'auto_import' => [
        'heading' => 'Samodejni uvoz',
        'label' => 'Samodejni uvoz iz odlagalne mape',

        'active_html' => 'Odlagalna mapa je aktivna. Beatrax vsakih 5 minut pregleda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> in išče nove datoteke.',
        'inactive_html' => 'Ko je vklopljeno, Beatrax vsakih 5 minut pregleda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> in išče datoteke <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> in <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> ter jih uvozi po istem matcherju kot čarovnik. Obdelane datoteke se premaknejo v <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, zato nikoli niso uvožene dvakrat.',
        'active_phone_html' => 'Odlagalna mapa je aktivna. Beatrax v ozadju pregleda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> in išče nove datoteke. Kdaj se pregled v ozadju izvede, odloči tvoj telefon — to so lahko minute ali ure.',
        'inactive_phone_html' => 'Ko je vklopljeno, Beatrax v ozadju pregleda <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> in išče datoteke <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> in <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> ter jih uvozi po istem matcherju kot čarovnik. Kdaj se pregled v ozadju izvede, odloči tvoj telefon — to so lahko minute ali ure. Obdelane datoteke se premaknejo v <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, zato nikoli niso uvožene dvakrat.',
    ],

    'aliases' => [
        'heading' => 'Aliasi',
        'intro' => 'Preglej in uredi razumljiva imena, ki jih Beatrax uporablja za nejasne opise z izpiskov.',
        'manage' => 'Upravljaj aliase →',
    ],

    'tax_heading' => 'Davek',
    'data_backup_heading' => 'Podatki in varnostna kopija',

    'about_updates' => [
        'heading' => 'O posodobitvah',
        'body' => 'Ko je Beatrax nameščen, se posodablja samodejno. Po namestitvi prve različice prihajajo nove prek pasice v aplikaciji — na GitHub se ti ni treba vračati. Če se katera od prihodnjih posodobitev ne bi namestila, lahko najnovejši namestitveni program vedno ročno preneseš s strani izdaj.',
        'body_phone' => 'Tu se Beatrax ne posodablja sam. Nove različice mobilne aplikacije prihajajo prek App Storea ali Googla Play, tako kot druge tvoje aplikacije. Stran izdaj navaja, kaj se je v vsaki spremenilo.',
        'open_releases' => 'Odpri stran izdaj →',
    ],

    'privacy' => [
        'heading' => 'Pravilnik o zasebnosti',
        'body' => 'Beatrax hrani tvoje finance na tvojih napravah. Pravilnik pojasnjuje, kaj to pomeni, kaj pošiljajo izbirne spletne funkcije in kako odstraniš svoje podatke.',
        'open' => 'Preberi pravilnik o zasebnosti →',
        'url_hint' => 'Če se povezava ne odpre, obišči:',
    ],

    'first_run_tour' => [
        'heading' => 'Vodnik ob prvem zagonu',
        'body' => 'Znova zaženi čarovnika za nastavitev, če želiš še enkrat skozi uvodni potek.',
        'run_again' => 'Znova zaženi čarovnika za nastavitev',
    ],

    'developer' => [
        'heading' => 'Razvijalec',
        'label' => 'Razvojna konzola v aplikaciji',
        'help' => 'Prikaže razvojno konzolo na /dev. Stikalo Napredno se ponastavi ob vsaki prijavi.',
        'aria' => 'Razvijalski način',
    ],

    'errors' => [
        'period_move_failed' => 'Proračunskega meseca ni bilo mogoče premakniti, zato je ostal, kjer je bil.',
        'currency_required' => 'Izberi valuto.',
        'window_months' => 'Izberi med 2 in 60 meseci.',
        'threshold' => 'Izberi prag: 1 %, 2 %, 5 %, 10 %, 25 % ali 50 %.',
        'amount' => 'Vnesi znesek od :zero navzgor.',
        'period_day' => 'Izberi dan od 1 do 28.',
        'currency_view' => 'Izberi eno od razpoložljivih možnosti.',
    ],
];
