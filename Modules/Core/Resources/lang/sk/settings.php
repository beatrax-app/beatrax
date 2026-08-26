<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Zobrazenie',
        'money' => 'Peniaze',
        'insights' => 'Analýzy a upozornenia',
        'security' => 'Bezpečnosť a zariadenia',
        'data' => 'Importy a údaje',
        'app' => 'Aplikácia',
    ],

    'title' => 'Nastavenia',
    'subtitle' => 'Predvoľby toho, ako sa tvoje financie zobrazujú v aplikácii.',

    'appearance' => [
        'heading' => 'Vzhľad',
        'theme' => 'Motív',
        'theme_light' => 'Svetlý',
        'theme_dark' => 'Tmavý',
        'theme_system' => 'Podľa systému',
        'theme_help' => 'Voľba podľa systému sleduje svetlé alebo tmavé nastavenie tvojho operačného systému.',
    ],

    'language' => [
        'apply' => 'Použiť',
        'heading' => 'Jazyk',
        'label' => 'Jazyk rozhrania',

        'system' => 'Podľa systému',
        'help' => 'Mení slová na obrazovke aj spôsob zápisu súm. Voľba podľa systému sleduje jazyk prehliadača alebo operačného systému, inak použije angličtinu.',
    ],

    'country' => [
        'heading' => 'Krajina',
        'label' => 'Tvoja krajina',
        'help' => 'Určuje, podľa ktorej krajiny aplikácia rozpoznáva daňové pravidlá, štátne inštitúcie a bankové poplatky. Jazyk ani spôsob zápisu súm nemení.',
        'choose' => 'Vyber krajinu…',
        'switch_note' => 'Zmena pridá nové kategórie — existujúce značky sa nikdy nemenia.',

        'wording_note' => 'Názvy daňových kategórií pochádzajú z daňového priznania používaného v :country, takže zostávajú v slovách danej krajiny vo všetkých jazykoch aplikácie.',

        'countries' => [
            'at' => 'Rakúsko',
            'be' => 'Belgicko',
            'bg' => 'Bulharsko',
            'ca' => 'Kanada',
            'ch' => 'Švajčiarsko',
            'cy' => 'Cyprus',
            'cz' => 'Česko',
            'de' => 'Nemecko',
            'dk' => 'Dánsko',
            'ee' => 'Estónsko',
            'es' => 'Španielsko',
            'fi' => 'Fínsko',
            'fr' => 'Francúzsko',
            'gb' => 'Spojené kráľovstvo',
            'gr' => 'Grécko',
            'hr' => 'Chorvátsko',
            'hu' => 'Maďarsko',
            'ie' => 'Írsko',
            'is' => 'Island',
            'it' => 'Taliansko',
            'lt' => 'Litva',
            'lu' => 'Luxembursko',
            'lv' => 'Lotyšsko',
            'mt' => 'Malta',
            'nl' => 'Holandsko',
            'no' => 'Nórsko',
            'pl' => 'Poľsko',
            'pt' => 'Portugalsko',
            'ro' => 'Rumunsko',
            'se' => 'Švédsko',
            'si' => 'Slovinsko',
            'sk' => 'Slovensko',
            'us' => 'Spojené štáty',
        ],
    ],

    'currency_display' => [
        'heading' => 'Zobrazenie meny',
        'label' => 'Predvolené zobrazenie v zozname transakcií',
        'eur_only' => 'Len :code',
        'original' => 'Pôvodná mena',
        'help' => 'V zozname transakcií to môžeš kedykoľvek prepnúť pre jednotlivé stránky.',
    ],

    'base_currency' => [
        'heading' => 'Základná mena výkazov',
        'label' => 'Mena výkazov',
        'help' => 'Všetky súčty a súhrny sa prepočítajú na túto menu. Každý účet naďalej zobrazuje vedľa aj svoju pôvodnú menu.',
    ],

    'exchange_rates' => [
        'heading' => 'Výmenné kurzy',
        'fetch_online' => 'Sťahovať aktuálne kurzy online',
        'online_on' => 'Kurzy sa denne sťahujú z ECB. Len dopyty na menové páry — žiadne osobné údaje.',
        'last_updated' => 'Naposledy aktualizované: :date.',
        'online_off' => 'Používajú sa priložené kurzy. Toto zariadenie neopúšťajú žiadne údaje.',
        'fetch_aria' => 'Stiahnuť aktuálne výmenné kurzy online',
        'refreshing' => 'Obnovuje sa…',
        'next_refresh' => 'Ďalšie automatické obnovenie: denne o 09:00',
        'refresh_gave_up' => 'Kurzy sa nepodarilo obnoviť. Naďalej sa používajú kurzy uložené v tomto zariadení.',
        'refresh_now' => 'Obnoviť teraz',
    ],

    'period' => [
        'heading' => 'Obdobie',
        'label' => 'Obdobie sa začína dňom',
        'help' => 'Číslo od 1 do 28. Väčšina ľudí tu necháva 1 (kalendárny mesiac). Zvoľ 25, ak ti výplata prichádza 25. a „tvoj mesiac“ sa pre teba začína vtedy.',
    ],

    'recurring' => [
        'heading' => 'Rozpoznávanie opakovaných platieb',
        'window_label' => 'Okno rozpoznávania (mesiace)',
        'window_help' => 'Koľko mesiacov histórie sa prehľadá pri zhlukovaní transakcií do opakovaných vzorov.',
        'income_label' => 'Minimálny príjem (centy)',
        'income_help' => 'Príjmy pod touto hranicou sa automaticky nezhlukujú. Ukladá sa v centoch — 200000 znamená :example. Nastav 0, ak chceš hranicu vypnúť.',
    ],

    'drift' => [
        'heading' => 'Upozornenia na odchýlky',
        'label' => 'Predvolený prah upozornenia na odchýlku',
        'help' => 'Upozornenie sa spustí, keď sa najnovšia suma opakovanej platby líši od predchádzajúcej o viac než toto percento. Nastavenie jednotlivej série má prednosť.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (predvolené)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Uložiť nastavenia',
    'saved' => 'Uložené.',

    'anomaly_heading' => 'Rozpoznávanie anomálií',
    'notifications_heading' => 'Oznámenia',

    'forecasting' => [
        'heading' => 'Prognózy',
        'intro' => 'Beatrax premieta tvoj zostatok dopredu z aktuálneho stavu účtov. Pri účtoch bez zostatkov z výpisu (PayPal, staršie importy CSV) tu nastav počiatočný zostatok, aby prognózy vychádzali zo známeho bodu.',
        'no_accounts' => 'Zatiaľ žiadne účty — naimportuj výpis z účtu a pridá sa.',
    ],

    'auto_import' => [
        'heading' => 'Automatický import',
        'label' => 'Automatický import z odkladacieho priečinka',

        'active_html' => 'Odkladací priečinok je aktívny. Beatrax každých 5 minút prehľadáva <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> a hľadá nové súbory.',
        'inactive_html' => 'Keď je zapnutý, Beatrax každých 5 minút prehľadáva <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> a hľadá súbory <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> a <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>, ktoré importuje rovnakou linkou párovania ako sprievodca. Spracované súbory sa presunú do <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, takže sa nikdy neimportujú dvakrát.',
    ],

    'aliases' => [
        'heading' => 'Aliasy',
        'intro' => 'Skontroluj a uprav zrozumiteľné názvy, ktoré má Beatrax priradené k záhadným popisom z výpisov.',
        'manage' => 'Spravovať aliasy →',
    ],

    'tax_heading' => 'Dane',
    'shared_merchant_heading' => 'Zdieľaný zoznam obchodníkov',
    'data_backup_heading' => 'Údaje a záloha',
    'install_heading' => 'Inštalácia',

    'about_updates' => [
        'heading' => 'O aktualizáciách',
        'body' => 'Po nainštalovaní sa Beatrax aktualizuje sám. Po inštalácii úplne prvej verzie prichádzajú ďalšie verzie cez banner priamo v aplikácii — na GitHub sa už vracať netreba. Ak by sa niektorá budúca aktualizácia nepodarila, najnovší inštalátor si vždy môžeš znova stiahnuť ručne zo stránky s vydaniami.',
        'open_releases' => 'Otvoriť stránku s vydaniami →',
    ],

    'privacy' => [
        'heading' => 'Zásady ochrany súkromia',
        'body' => 'Beatrax drží tvoje financie na tvojich vlastných zariadeniach. Zásady vysvetľujú, čo to znamená, čo posielajú voliteľné online funkcie a ako svoje údaje odstrániť.',
        'open' => 'Prečítať zásady ochrany súkromia →',
        'url_hint' => 'Ak sa odkaz neotvorí, navštív:',
    ],

    'first_run_tour' => [
        'heading' => 'Úvodná prehliadka',
        'body' => 'Spusti sprievodcu nastavením znova, ak si chceš prejsť úvodný postup ešte raz.',
        'run_again' => 'Spustiť sprievodcu nastavením znova',
    ],

    'developer' => [
        'heading' => 'Vývojár',
        'label' => 'Vývojárska konzola v aplikácii',
        'help' => 'Zobrazí vývojársku konzolu na /dev. Prepínač Rozšírené sa resetuje pri každom prihlásení.',
        'aria' => 'Vývojársky režim',
    ],

    'errors' => [
        'currency_required' => 'Vyber menu.',
        'window_months' => 'Zvoľ hodnotu od 2 do 60 mesiacov.',
        'threshold' => 'Zvoľ prah 1%, 2%, 5%, 10%, 25% alebo 50%.',
        'amount' => 'Zadaj sumu od :zero vyššie.',
        'period_day' => 'Zvoľ deň od 1 do 28.',
        'currency_view' => 'Vyber jednu z dostupných možností.',
    ],
];
