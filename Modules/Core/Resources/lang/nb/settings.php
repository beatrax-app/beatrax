<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Visning',
        'money' => 'Penger',
        'insights' => 'Innsikt & varsler',
        'security' => 'Sikkerhet & enheter',
        'data' => 'Import & data',
        'app' => 'App',
    ],

    'title' => 'Innstillinger',
    'subtitle' => 'Innstillinger for hvordan økonomien din vises i appen.',

    'appearance' => [
        'heading' => 'Utseende',
        'theme' => 'Tema',
        'theme_light' => 'Lyst',
        'theme_dark' => 'Mørkt',
        'theme_system' => 'System',
        'theme_help' => 'System følger lys- eller mørkeinnstillingen i operativsystemet ditt.',
    ],

    'language' => [
        'apply' => 'Bruk',
        'heading' => 'Språk',
        'label' => 'Visningsspråk',

        'system' => 'System',
        'help' => 'Endrer ordene på skjermen og hvordan beløp skrives. System følger språket i nettleseren eller operativsystemet ditt, med engelsk som standard.',
    ],

    'country' => [
        'heading' => 'Land',
        'label' => 'Landet ditt',
        'help' => 'Bestemmer hvilket lands skatteregler, offentlige etater og bankgebyrer appen kjenner igjen. Det endrer ikke språket eller hvordan beløp skrives.',
        'choose' => 'Velg et land…',
        'switch_note' => 'Et bytte legger til nye kategorier — eksisterende merkinger endres aldri.',

        'wording_note' => 'Navnene på skattekategoriene vises på ditt språk; skattemeldingen i :country bruker sine egne ord.',

        'countries' => [
            'at' => 'Østerrike',
            'be' => 'Belgia',
            'bg' => 'Bulgaria',
            'ca' => 'Canada',
            'ch' => 'Sveits',
            'cy' => 'Kypros',
            'cz' => 'Tsjekkia',
            'de' => 'Tyskland',
            'dk' => 'Danmark',
            'ee' => 'Estland',
            'es' => 'Spania',
            'fi' => 'Finland',
            'fr' => 'Frankrike',
            'gb' => 'Storbritannia',
            'gr' => 'Hellas',
            'hr' => 'Kroatia',
            'hu' => 'Ungarn',
            'ie' => 'Irland',
            'is' => 'Island',
            'it' => 'Italia',
            'lt' => 'Litauen',
            'lu' => 'Luxembourg',
            'lv' => 'Latvia',
            'mt' => 'Malta',
            'nl' => 'Nederland',
            'no' => 'Norge',
            'pl' => 'Polen',
            'pt' => 'Portugal',
            'ro' => 'Romania',
            'se' => 'Sverige',
            'si' => 'Slovenia',
            'sk' => 'Slovakia',
            'us' => 'USA',
        ],
    ],

    'currency_display' => [
        'heading' => 'Beløpsvisning',
        'label' => 'Standardvisning i transaksjonslisten',
        'eur_only' => 'Oppgjort beløp',
        'original' => 'Opprinnelig beløp',
        'help' => 'Du kan fortsatt bytte per side fra transaksjonslisten.',
    ],

    'base_currency' => [
        'heading' => 'Basisvaluta for rapportering',
        'label' => 'Rapporteringsvaluta',
        'help' => 'Alle summer og oppsummeringer regnes om til denne valutaen. Hver konto viser fortsatt sin egen opprinnelige valuta ved siden av.',
    ],

    'exchange_rates' => [
        'heading' => 'Valutakurser',
        'fetch_online' => 'Hent oppdaterte kurser på nett',
        'online_on' => 'Kurser hentes daglig fra ECB. Kun oppslag av valutapar — ingen personopplysninger.',
        'last_updated' => 'Sist oppdatert: :date.',
        'online_off' => 'Medfølgende kurser brukes. Ingen data forlater denne enheten.',
        'fetch_aria' => 'Hent oppdaterte valutakurser på nett',
        'refreshing' => 'Oppdaterer…',
        'next_refresh' => 'Automatisk oppdatering: én gang om dagen',
        'refresh_gave_up' => 'Kunne ikke oppdatere kursene. Kursene som allerede ligger på enheten, brukes fortsatt.',
        'refresh_now' => 'Oppdater nå',
    ],

    'period' => [
        'heading' => 'Periode',
        'label' => 'Perioden starter på dag',
        'help' => 'Nummerert fra 1 til 28. De fleste lar denne stå på 1 (kalendermåned). Bruk 25 hvis lønnen kommer den 25., og du tenker på "din måned" som noe som starter da.',

        'move_confirm' => 'Hvis perioden starter på dag :day, blir alle konvoluttbeløp arkivert på nytt og lagt sammen der to måneder faller sammen til én. Å sette dagen tilbake deler dem ikke opp igjen.',
        'move_cancel' => 'Avbryt',
        'move_apply' => 'Bruk',
    ],

    'recurring' => [
        'heading' => 'Gjenkjenning av gjentakende betalinger',
        'window_label' => 'Gjenkjenningsvindu (måneder)',
        'window_help' => 'Hvor mange måneder med historikk som gjennomsøkes når transaksjoner grupperes i gjentakende mønstre.',
        'income_label' => 'Minste inntekt (minste enheter)',
        'income_help' => 'Inntekter under denne terskelen grupperes ikke automatisk. Lagres i minste enheter — :minor betyr :example. Sett den til 0 for å slå av terskelen.',
    ],

    'drift' => [
        'heading' => 'Avviksvarsler',
        'label' => 'Standardterskel for avviksvarsler',
        'help' => 'Varsler utløses når det siste beløpet for en gjentakende belastning avviker mer enn denne prosentandelen fra forrige beløp. Innstillinger per serie går foran.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (standard)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Lagre innstillinger',
    'saved' => 'Lagret.',

    'anomaly_heading' => 'Anomalideteksjon',
    'notifications_heading' => 'Varsler',

    'forecasting' => [
        'heading' => 'Prognoser',
        'intro' => 'Beatrax framskriver saldoen din ut fra den nåværende stillingen på kontoene dine. For kontoer uten saldo fra kontoutskrift (PayPal, gamle CSV-importer) angir du inngående saldo her, slik at prognosene starter fra et kjent punkt.',
        'no_accounts' => 'Ingen kontoer ennå — importer en kontoutskrift for å legge til en.',
    ],

    'auto_import' => [
        'heading' => 'Automatisk import',
        'label' => 'Automatisk import fra slippmappen',

        'active_html' => 'Slippmappen er aktiv. Beatrax gjennomsøker <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> hvert 5. minutt etter nye filer.',
        'inactive_html' => 'Når funksjonen er på, gjennomsøker Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> hvert 5. minutt etter <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- og <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-filer og importerer dem gjennom den samme matcher-pipelinen som veiviseren. Behandlede filer flyttes til <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, slik at de aldri importeres to ganger.',
        'active_phone_html' => 'Slippmappen er aktiv. Beatrax gjennomsøker <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> etter nye filer i bakgrunnen. Telefonen din bestemmer når et bakgrunnssøk kjører, så det kan ta minutter eller timer.',
        'inactive_phone_html' => 'Når funksjonen er på, gjennomsøker Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> i bakgrunnen etter <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- og <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-filer og importerer dem gjennom den samme matcher-pipelinen som veiviseren. Telefonen din bestemmer når et bakgrunnssøk kjører, så det kan ta minutter eller timer. Behandlede filer flyttes til <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code>, slik at de aldri importeres to ganger.',
    ],

    'aliases' => [
        'heading' => 'Aliaser',
        'intro' => 'Se gjennom og rediger de forståelige navnene du har lært Beatrax for kryptiske beskrivelser i kontoutskrifter.',
        'manage' => 'Administrer aliaser →',
    ],

    'tax_heading' => 'Skatt',
    'data_backup_heading' => 'Data & sikkerhetskopi',

    'about_updates' => [
        'heading' => 'Om oppdateringer',
        'body' => 'Beatrax oppdaterer seg selv automatisk når appen først er installert. Etter at du har installert aller første versjon, kommer kommende versjoner via et banner i appen — du trenger ikke gå tilbake til GitHub. Skulle en framtidig oppdatering en gang mislykkes, kan du alltid laste ned det nyeste installasjonsprogrammet manuelt fra utgivelsessiden.',
        'body_phone' => 'Her oppdaterer ikke Beatrax seg selv. Nye versjoner av telefonappen kommer via App Store eller Google Play, akkurat som de andre appene dine. Utgivelsessiden viser hva som er endret i hver enkelt.',
        'check_label' => 'Se etter oppdateringer automatisk',
        'check_on' => 'Beatrax spør utgivelsesstrømmen om det finnes en nyere signert versjon. Ingenting lastes ned før du selv velger å installere den.',
        'check_off' => 'Det ses ikke etter oppdateringer, og ingenting forlater denne enheten. Nye versjoner finner du ved å åpne utgivelsessiden selv.',
        'open_releases' => 'Åpne utgivelsessiden →',
    ],

    'privacy' => [
        'heading' => 'Personvernerklæring',
        'body' => 'Beatrax holder økonomien din på dine egne enheter. Erklæringen forklarer hva det betyr, hva de valgfrie nettfunksjonene sender, og hvordan du fjerner dataene dine.',
        'open' => 'Les personvernerklæringen →',
        'url_hint' => 'Hvis lenken ikke åpnes, gå til:',
    ],

    'first_run_tour' => [
        'heading' => 'Omvisning ved første oppstart',
        'body' => 'Start oppsettsveiviseren på nytt hvis du vil gå gjennom introduksjonen en gang til.',
        'run_again' => 'Kjør oppsettsveiviseren på nytt',
    ],

    'developer' => [
        'heading' => 'Utvikler',
        'label' => 'Utviklerkonsoll i appen',
        'help' => 'Vis utviklerkonsollen på /dev. Tilbakestiller bryteren Avansert ved hver innlogging.',
        'aria' => 'Utviklermodus',
    ],

    'errors' => [
        'period_move_failed' => 'Budsjettmåneden kunne ikke flyttes, så den ble stående der den var.',
        'currency_required' => 'Velg en valuta.',
        'window_months' => 'Velg mellom 2 og 60 måneder.',
        'threshold' => 'Velg en terskel på 1 %, 2 %, 5 %, 10 %, 25 % eller 50 %.',
        'amount' => 'Skriv inn et beløp fra :zero og oppover.',
        'period_day' => 'Velg en dag fra 1 til 28.',
        'currency_view' => 'Velg ett av de tilgjengelige alternativene.',
    ],
];
