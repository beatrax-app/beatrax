<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Kuvamine',
        'money' => 'Raha',
        'insights' => 'Ülevaated ja hoiatused',
        'security' => 'Turvalisus ja seadmed',
        'data' => 'Impordid ja andmed',
        'app' => 'Rakendus',
    ],

    'title' => 'Seaded',
    'subtitle' => 'Eelistused selle kohta, kuidas sinu rahaasjad rakenduses kuvatakse.',

    'appearance' => [
        'heading' => 'Välimus',
        'theme' => 'Teema',
        'theme_light' => 'Hele',
        'theme_dark' => 'Tume',
        'theme_system' => 'Süsteemne',
        'theme_help' => 'Süsteemne järgib sinu operatsioonisüsteemi heleda või tumeda režiimi seadet.',
    ],

    'language' => [
        'apply' => 'Rakenda',
        'heading' => 'Keel',
        'label' => 'Kuvamise keel',

        'system' => 'Süsteemne',
        'help' => 'Muudab ekraanil olevaid sõnu ja summade kirjapilti. Süsteemne järgib brauseri või operatsioonisüsteemi keelt, vaikimisi inglise keelt.',
    ],

    'country' => [
        'heading' => 'Riik',
        'label' => 'Sinu riik',
        'help' => 'Määrab, millise riigi maksureegleid, riigiasutusi ja pangatasusid rakendus tunneb. Keelt ega summade kirjapilti see ei muuda.',
        'choose' => 'Vali riik…',
        'switch_note' => 'Vahetamine lisab uusi kategooriaid — olemasolevaid märgendeid ei muudeta kunagi.',

        'wording_note' => 'Maksukategooriate nimed pärinevad :country riigis kasutatavalt maksudeklaratsioonilt, seega jäävad need selle riigi sõnadesse igas rakenduse keeles.',

        'countries' => [
            'at' => 'Austria',
            'be' => 'Belgia',
            'bg' => 'Bulgaaria',
            'ca' => 'Kanada',
            'ch' => 'Šveits',
            'cy' => 'Küpros',
            'cz' => 'Tšehhi',
            'de' => 'Saksamaa',
            'dk' => 'Taani',
            'ee' => 'Eesti',
            'es' => 'Hispaania',
            'fi' => 'Soome',
            'fr' => 'Prantsusmaa',
            'gb' => 'Ühendkuningriik',
            'gr' => 'Kreeka',
            'hr' => 'Horvaatia',
            'hu' => 'Ungari',
            'ie' => 'Iirimaa',
            'is' => 'Island',
            'it' => 'Itaalia',
            'lt' => 'Leedu',
            'lu' => 'Luksemburg',
            'lv' => 'Läti',
            'mt' => 'Malta',
            'nl' => 'Holland',
            'no' => 'Norra',
            'pl' => 'Poola',
            'pt' => 'Portugal',
            'ro' => 'Rumeenia',
            'se' => 'Rootsi',
            'si' => 'Sloveenia',
            'sk' => 'Slovakkia',
            'us' => 'Ameerika Ühendriigid',
        ],
    ],

    'currency_display' => [
        'heading' => 'Valuuta kuvamine',
        'label' => 'Vaikevaade tehingute loendis',
        'eur_only' => 'Ainult EUR',
        'original' => 'Algne valuuta',
        'help' => 'Tehingute loendis saad seda igal lehel endiselt vahetada.',
    ],

    'base_currency' => [
        'heading' => 'Aruandluse põhivaluuta',
        'label' => 'Aruandlusvaluuta',
        'help' => 'Kõik kogusummad ja koondnäitajad teisendatakse sellesse valuutasse. Iga konto näitab kõrval endiselt oma algset valuutat.',
    ],

    'exchange_rates' => [
        'heading' => 'Vahetuskursid',
        'fetch_online' => 'Tõmba ajakohased kursid veebist',
        'online_on' => 'Kursid tõmmatakse iga päev ECB-st. Ainult valuutapaaride päringud — isikuandmeid ei saadeta.',
        'last_updated' => 'Viimati uuendatud: :date.',
        'online_off' => 'Kasutusel on kaasas olevad kursid. Andmed ei lahku sellest seadmest.',
        'fetch_aria' => 'Tõmba ajakohased vahetuskursid veebist',
        'refreshing' => 'Värskendan…',
        'next_refresh' => 'Järgmine automaatne värskendus: iga päev kell 09.00',
        'refresh_gave_up' => 'Kursse ei õnnestunud värskendada. Kasutusel on endiselt seadmes olevad kursid.',
        'refresh_now' => 'Värskenda kohe',
    ],

    'period' => [
        'heading' => 'Periood',
        'label' => 'Periood algab päeval',
        'help' => 'Numbrid 1 kuni 28. Enamik kasutajaid jätab siia 1 (kalendrikuu). Kasuta 25, kui palk laekub 25. kuupäeval ja mõtled „oma kuust“ sealt alates.',
    ],

    'recurring' => [
        'heading' => 'Korduvmaksete tuvastamine',
        'window_label' => 'Tuvastamise aken (kuudes)',
        'window_help' => 'Kui mitme kuu ajalugu skannitakse, kui tehinguid korduvateks mustriteks rühmitatakse.',
        'income_label' => 'Tulu miinimum (sentides)',
        'income_help' => 'Sellest lävest väiksemaid tulusid automaatselt ei rühmitata. Salvestatakse sentides — 200000 tähendab 2000,00 €. Läve väljalülitamiseks pane 0.',
    ],

    'drift' => [
        'heading' => 'Muutuste hoiatused',
        'label' => 'Muutuse hoiatuse vaikimisi lävi',
        'help' => 'Hoiatused käivituvad, kui korduva makse viimane summa erineb eelmisest summast rohkem kui selle protsendi võrra. Seeriapõhised erandid on ülimuslikud.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (vaikimisi)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Salvesta seaded',
    'saved' => 'Salvestatud.',

    'anomaly_heading' => 'Kõrvalekallete tuvastamine',
    'notifications_heading' => 'Teavitused',

    'forecasting' => [
        'heading' => 'Prognoosimine',
        'intro' => 'Beatrax prognoosib sinu jääki edasi kontode praegusest seisust. Kontodel, millel puuduvad väljavõtte jäägid (PayPal, vanad CSV-impordid), määra siin algjääk, et prognoosid algaksid teadaolevast punktist.',
        'no_accounts' => 'Kontosid veel pole — konto lisamiseks impordi kontoväljavõte.',
    ],

    'auto_import' => [
        'heading' => 'Automaatne import',
        'label' => 'Automaatne import jälgitavast kaustast',

        'active_html' => 'Jälgitav kaust on aktiivne. Beatrax kontrollib uute failide osas kausta <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> iga 5 minuti järel.',
        'inactive_html' => 'Sisselülitatuna kontrollib Beatrax kausta <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> iga 5 minuti järel failide <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> ja <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> osas ning impordib need sama sobitamise konveieri kaudu nagu viisard. Töödeldud failid liiguvad kausta <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, nii et neid ei impordita kunagi kaks korda.',
    ],

    'aliases' => [
        'heading' => 'Aliased',
        'intro' => 'Vaata üle ja muuda arusaadavaid nimesid, mille oled Beatraxile krüptiliste väljavõttekirjelduste jaoks õpetanud.',
        'manage' => 'Halda aliaseid →',
    ],

    'tax_heading' => 'Maksud',
    'shared_merchant_heading' => 'Jagatud kaupmeeste nimekiri',
    'data_backup_heading' => 'Andmed ja varundus',
    'install_heading' => 'Paigaldamine',

    'about_updates' => [
        'heading' => 'Uuendustest',
        'body' => 'Pärast paigaldamist uuendab Beatrax end automaatselt. Kui oled kõige esimese versiooni paigaldanud, saabuvad edaspidised versioonid rakendusesisese ribana — GitHubi pole vaja uuesti külastada. Kui mõni tulevane uuendus peaks rakendumata jääma, saad uusima paigaldusfaili alati väljalasete lehelt käsitsi uuesti alla laadida.',
        'open_releases' => 'Ava väljalasete leht →',
    ],

    'privacy' => [
        'heading' => 'Privaatsuspoliitika',
        'body' => 'Beatrax hoiab su rahaasju sinu enda seadmetes. Poliitika selgitab, mida see tähendab, mida valikulised veebifunktsioonid saadavad ja kuidas oma andmeid eemaldada.',
        'open' => 'Loe privaatsuspoliitikat →',
        'url_hint' => 'Kui link ei avane, mine aadressile:',
    ],

    'first_run_tour' => [
        'heading' => 'Esmakäivituse tutvustus',
        'body' => 'Käivita seadistusviisard uuesti, kui soovid tutvustava voo uuesti läbi teha.',
        'run_again' => 'Käivita seadistusviisard uuesti',
    ],

    'developer' => [
        'heading' => 'Arendaja',
        'label' => 'Rakendusesisene arenduskonsool',
        'help' => 'Näita arenduskonsooli aadressil /dev. Lähtestab täpsema režiimi lüliti igal sisselogimisel.',
        'aria' => 'Arendusrežiim',
    ],

    'errors' => [
        'currency_required' => 'Vali valuuta.',
        'window_months' => 'Vali vahemikus 2 kuni 60 kuud.',
        'threshold' => 'Vali lävi: 1%, 2%, 5%, 10%, 25% või 50%.',
        'amount' => 'Sisesta summa alates 0 €.',
        'period_day' => 'Vali päev 1 kuni 28.',
        'currency_view' => 'Vali üks saadaolevatest valikutest.',
    ],
];
