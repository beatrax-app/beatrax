<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Visning',
        'money' => 'Penge',
        'insights' => 'Indsigter & advarsler',
        'security' => 'Sikkerhed & enheder',
        'data' => 'Import & data',
        'app' => 'App',
    ],

    'title' => 'Indstillinger',
    'subtitle' => 'Præferencer for, hvordan din økonomi vises i appen.',

    'appearance' => [
        'heading' => 'Udseende',
        'theme' => 'Tema',
        'theme_light' => 'Lyst',
        'theme_dark' => 'Mørkt',
        'theme_system' => 'System',
        'theme_help' => 'System følger lys- eller mørkeindstillingen i dit styresystem.',
    ],

    'language' => [
        'apply' => 'Anvend',
        'heading' => 'Sprog',
        'label' => 'Visningssprog',

        'system' => 'System',
        'help' => 'Ændrer ordene på skærmen og måden, beløb skrives på. System følger sproget i din browser eller dit styresystem med engelsk som standard.',
    ],

    'country' => [
        'heading' => 'Land',
        'label' => 'Dit land',
        'help' => 'Bestemmer hvilket lands skatteregler, offentlige myndigheder og bankgebyrer appen genkender. Det ændrer ikke sproget eller måden, beløb skrives på.',
        'choose' => 'Vælg et land…',
        'switch_note' => 'Et skift tilføjer nye kategorier — eksisterende markeringer ændres aldrig.',

        'wording_note' => 'Navnene på skattekategorierne vises på dit sprog; selvangivelsen i :country bruger sine egne ord.',

        'countries' => [
            'at' => 'Østrig',
            'be' => 'Belgien',
            'bg' => 'Bulgarien',
            'ca' => 'Canada',
            'ch' => 'Schweiz',
            'cy' => 'Cypern',
            'cz' => 'Tjekkiet',
            'de' => 'Tyskland',
            'dk' => 'Danmark',
            'ee' => 'Estland',
            'es' => 'Spanien',
            'fi' => 'Finland',
            'fr' => 'Frankrig',
            'gb' => 'Storbritannien',
            'gr' => 'Grækenland',
            'hr' => 'Kroatien',
            'hu' => 'Ungarn',
            'ie' => 'Irland',
            'is' => 'Island',
            'it' => 'Italien',
            'lt' => 'Litauen',
            'lu' => 'Luxembourg',
            'lv' => 'Letland',
            'mt' => 'Malta',
            'nl' => 'Nederlandene',
            'no' => 'Norge',
            'pl' => 'Polen',
            'pt' => 'Portugal',
            'ro' => 'Rumænien',
            'se' => 'Sverige',
            'si' => 'Slovenien',
            'sk' => 'Slovakiet',
            'us' => 'USA',
        ],
    ],

    'currency_display' => [
        'heading' => 'Beløbsvisning',
        'label' => 'Standardvisning af beløb',
        'eur_only' => 'Afregnet beløb',
        'original' => 'Oprindeligt beløb',
        'help' => 'Gælder transaktionslisten og totalerne i overblikket. Du kan stadig skifte pr. side, men kun fra transaktionslisten.',
    ],

    'base_currency' => [
        'heading' => 'Basisvaluta til rapportering',
        'label' => 'Rapporteringsvaluta',
        'help' => 'Alle totaler og opsummeringer omregnes til denne valuta. Hver konto viser stadig sin egen oprindelige valuta ved siden af.',
    ],

    'exchange_rates' => [
        'heading' => 'Valutakurser',
        'fetch_online' => 'Hent aktuelle kurser online',
        'online_on' => 'Kurser hentes dagligt fra ECB, eller fra Frankfurter hvis ECB ikke kan nås. Kun opslag af valutapar — ingen personoplysninger.',
        'last_updated' => 'Sidst opdateret: :date.',
        'online_off' => 'Kurserne, der allerede findes, bruges fortsat, med det medfølgende øjebliksbillede som reserve. Ingen data forlader denne enhed.',
        'fetch_aria' => 'Hent aktuelle valutakurser online',
        'refreshing' => 'Opdaterer…',
        'next_refresh' => 'Automatisk opdatering: en gang om dagen',
        'refresh_gave_up' => 'Kunne ikke opdatere kurserne. Kurserne på denne enhed bruges fortsat.',
        'refresh_now' => 'Opdatér nu',
    ],

    'period' => [
        'heading' => 'Periode',
        'label' => 'Perioden begynder på dag',
        'help' => 'Nummereret fra 1 til 28. De fleste lader den stå på 1 (kalendermåned). Brug 25, hvis din løn går ind den 25., og du tænker på "din måned" som noget, der begynder der.',

        'move_confirm' => 'Hvis perioden starter på dag :day, omplaceres alle beløb i kuverterne, og to lægges sammen, hvor to måneder falder sammen til én. At sætte dagen tilbage deler dem ikke op igen.',
        'move_cancel' => 'Annullér',
        'move_apply' => 'Anvend',
    ],

    'recurring' => [
        'heading' => 'Registrering af tilbagevendende betalinger',
        'window_label' => 'Registreringsvindue (måneder)',
        'window_help' => 'Hvor mange måneders historik der gennemsøges, når transaktioner grupperes i tilbagevendende mønstre.',
        'income_label' => 'Mindste indtægt (mindste enheder)',
        'income_help' => 'Indtægter under denne tærskel grupperes ikke automatisk. Gemmes i mindste enheder — :minor betyder :example. Sæt den til 0 for at slå tærsklen fra.',
    ],

    'drift' => [
        'heading' => 'Afvigelsesadvarsler',
        'label' => 'Standardtærskel for afvigelsesadvarsler',
        'help' => 'Advarsler udløses, når det seneste beløb for en tilbagevendende postering afviger mere end denne procentdel fra det forrige beløb. Indstillinger pr. serie har forrang.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (standard)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Gem indstillinger',
    'saved' => 'Gemt.',

    'anomaly_heading' => 'Anomalidetektion',
    'notifications_heading' => 'Notifikationer',

    'forecasting' => [
        'heading' => 'Prognoser',
        'intro' => 'Beatrax fremskriver din saldo ud fra kontienes aktuelle stand. For konti uden saldo fra kontoudtog (PayPal, gamle CSV-import) angiver du startsaldoen her, så prognoserne begynder fra et kendt punkt.',
        'no_accounts' => 'Ingen konti endnu — importér et kontoudtog for at tilføje en.',
    ],

    'auto_import' => [
        'heading' => 'Automatisk import',
        'label' => 'Automatisk import fra afleveringsmappen',

        'active_html' => 'Afleveringsmappen er aktiv. Beatrax gennemsøger <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> hvert 5. minut for nye filer.',
        'inactive_html' => 'Når funktionen er slået til, gennemsøger Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> hvert 5. minut for <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- og <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-filer og importerer dem gennem den samme matcher-pipeline som guiden. Behandlede filer flyttes til <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, så de aldrig importeres to gange.',
        'active_phone_html' => 'Afleveringsmappen er aktiv. Beatrax gennemsøger <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> for nye filer i baggrunden. Din telefon bestemmer, hvornår en baggrundssøgning kører, så der kan gå minutter eller timer.',
        'inactive_phone_html' => 'Når funktionen er slået til, gennemsøger Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> i baggrunden for <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- og <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-filer og importerer dem gennem den samme matcher-pipeline som guiden. Din telefon bestemmer, hvornår en baggrundssøgning kører, så der kan gå minutter eller timer. Behandlede filer flyttes til <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, så de aldrig importeres to gange.',
    ],

    'aliases' => [
        'heading' => 'Aliasser',
        'intro' => 'Gennemgå og redigér de letforståelige navne, du har lært Beatrax for kryptiske beskrivelser i kontoudtog.',
        'manage' => 'Administrér aliasser →',
    ],

    'tax_heading' => 'Skat',
    'data_backup_heading' => 'Data & sikkerhedskopi',

    'about_updates' => [
        'heading' => 'Om opdateringer',
        'body' => 'Beatrax opdaterer sig selv automatisk, når appen først er installeret. Efter installationen af den allerførste version kommer fremtidige versioner via et banner i appen — du behøver ikke besøge GitHub igen. Skulle en fremtidig opdatering mislykkes, kan du altid hente det nyeste installationsprogram manuelt fra udgivelsessiden.',
        'body_phone' => 'Her opdaterer Beatrax ikke sig selv. Nye versioner af telefon-appen kommer via App Store eller Google Play, ligesom dine andre apps.',
        'check_label' => 'Søg automatisk efter opdateringer',
        'check_on' => 'Beatrax spørger udgivelsesfeedet, om der findes en nyere signeret version. Der hentes intet, før du selv vælger at installere den.',
        'check_off' => 'Der søges ikke efter opdateringer, og intet forlader denne enhed. Nye versioner finder du ved selv at åbne udgivelsessiden.',
        'open_releases' => 'Åbn udgivelsessiden →',
    ],

    'privacy' => [
        'heading' => 'Privatlivspolitik',
        'body' => 'Beatrax holder din økonomi på dine egne enheder. Politikken forklarer, hvad det betyder, hvad de valgfri onlinefunktioner sender, og hvordan du fjerner dine data.',
        'open' => 'Læs privatlivspolitikken →',
        'url_hint' => 'Hvis linket ikke åbner, så besøg:',
    ],

    'first_run_tour' => [
        'heading' => 'Rundvisning ved første opstart',
        'body' => 'Start opsætningsguiden igen, hvis du vil gennemgå introduktionen en gang til.',
        'run_again' => 'Kør opsætningsguiden igen',
    ],

    'developer' => [
        'heading' => 'Udvikler',
        'label' => 'Udviklerkonsol i appen',
        'help' => 'Vis udviklerkonsollen på /dev. Nulstiller kontakten Avanceret ved hvert login.',
        'aria' => 'Udviklertilstand',
    ],

    'errors' => [
        'period_move_failed' => 'Budgetmåneden kunne ikke flyttes, så den blev, hvor den var.',
        'currency_required' => 'Vælg en valuta.',
        'window_months' => 'Vælg mellem 2 og 60 måneder.',
        'threshold' => 'Vælg en tærskel på 1 %, 2 %, 5 %, 10 %, 25 % eller 50 %.',
        'amount' => 'Indtast et beløb fra :zero og opefter.',
        'period_day' => 'Vælg en dag fra 1 til 28.',
        'currency_view' => 'Vælg en af de tilgængelige muligheder.',
    ],
];
