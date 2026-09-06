<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Visning',
        'money' => 'Pengar',
        'insights' => 'Insikter & varningar',
        'security' => 'Säkerhet & enheter',
        'data' => 'Import & data',
        'app' => 'App',
    ],

    'title' => 'Inställningar',
    'subtitle' => 'Inställningar för hur din ekonomi visas i appen.',

    'appearance' => [
        'heading' => 'Utseende',
        'theme' => 'Tema',
        'theme_light' => 'Ljust',
        'theme_dark' => 'Mörkt',
        'theme_system' => 'System',
        'theme_help' => 'System följer ljus- eller mörkerinställningen i ditt operativsystem.',
    ],

    'language' => [
        'apply' => 'Tillämpa',
        'heading' => 'Språk',
        'label' => 'Visningsspråk',

        'system' => 'System',
        'help' => 'Ändrar orden på skärmen och hur belopp skrivs. System följer språket i din webbläsare eller ditt operativsystem, med engelska som standard.',
    ],

    'timezone' => [
        'heading' => 'Tidszon',
        'label' => 'Tidszon för den här installationen',
        'help' => 'Avgör vilken dag en transaktion hamnar på och i vilken ram tider sparas. Parkopplade enheter delar den här inställningen, så båda läser samma dag.',
        'this_machine' => 'Den här datorn (:zone)',
    ],

    'country' => [
        'heading' => 'Land',
        'label' => 'Ditt land',
        'help' => 'Avgör vilket lands skatteregler, myndigheter och bankavgifter appen känner igen. Det ändrar inte språket eller hur belopp skrivs.',
        'choose' => 'Välj ett land…',
        'switch_note' => 'Att byta lägger till nya kategorier — befintliga märkningar ändras aldrig.',

        'wording_note' => 'Namnen på skattekategorierna visas på ditt språk; deklarationen i :country använder sina egna ord.',

        'countries' => [
            'at' => 'Österrike',
            'be' => 'Belgien',
            'bg' => 'Bulgarien',
            'ca' => 'Kanada',
            'ch' => 'Schweiz',
            'cy' => 'Cypern',
            'cz' => 'Tjeckien',
            'de' => 'Tyskland',
            'dk' => 'Danmark',
            'ee' => 'Estland',
            'es' => 'Spanien',
            'fi' => 'Finland',
            'fr' => 'Frankrike',
            'gb' => 'Storbritannien',
            'gr' => 'Grekland',
            'hr' => 'Kroatien',
            'hu' => 'Ungern',
            'ie' => 'Irland',
            'is' => 'Island',
            'it' => 'Italien',
            'lt' => 'Litauen',
            'lu' => 'Luxemburg',
            'lv' => 'Lettland',
            'mt' => 'Malta',
            'nl' => 'Nederländerna',
            'no' => 'Norge',
            'pl' => 'Polen',
            'pt' => 'Portugal',
            'ro' => 'Rumänien',
            'se' => 'Sverige',
            'si' => 'Slovenien',
            'sk' => 'Slovakien',
            'us' => 'USA',
        ],
    ],

    'currency_display' => [
        'heading' => 'Beloppsvisning',
        'label' => 'Standardvy för belopp',
        'eur_only' => 'Reglerat belopp',
        'original' => 'Ursprungligt belopp',
        'help' => 'Gäller transaktionslistan och summorna i översikten. Du kan fortfarande växla per sida, men bara från transaktionslistan.',
    ],

    'base_currency' => [
        'heading' => 'Basvaluta för rapportering',
        'label' => 'Rapporteringsvaluta',
        'help' => 'Alla summor och sammanställningar räknas om till den här valutan. Varje konto visar fortfarande sin egen ursprungliga valuta bredvid.',
    ],

    'exchange_rates' => [
        'heading' => 'Växelkurser',
        'fetch_online' => 'Hämta aktuella kurser online',
        'online_on' => 'Kurser hämtas dagligen från ECB, eller från Frankfurter om ECB inte går att nå. Endast uppslag av valutapar — inga personuppgifter.',
        'last_updated' => 'Senast uppdaterad: :date.',
        'online_off' => 'Kurserna som redan finns används fortfarande, med den medföljande ögonblicksbilden som reserv. Inga data lämnar den här enheten.',
        'fetch_aria' => 'Hämta aktuella växelkurser online',
        'refreshing' => 'Uppdaterar…',
        'next_refresh' => 'Automatisk uppdatering: en gång om dagen',
        'refresh_gave_up' => 'Kunde inte uppdatera kurserna. Kurserna som redan finns på enheten används fortfarande.',
        'refresh_now' => 'Uppdatera nu',
    ],

    'period' => [
        'heading' => 'Period',
        'label' => 'Perioden börjar dag',
        'help' => 'Numrerat 1 till 28. De flesta har kvar 1 (kalendermånad). Använd 25 om lönen kommer den 25 varje månad och du tänker på "din månad" som att den börjar då.',

        'move_confirm' => 'Om perioden börjar dag :day flyttas alla kuvertbelopp om och läggs ihop där två månader går samman till en. Att ställa tillbaka dagen delar inte upp dem igen.',
        'move_cancel' => 'Avbryt',
        'move_apply' => 'Tillämpa',
    ],

    'recurring' => [
        'heading' => 'Detektering av återkommande betalningar',
        'window_label' => 'Detekteringsfönster (månader)',
        'window_help' => 'Hur många månaders historik som genomsöks när transaktioner grupperas till återkommande mönster.',
        'income_label' => 'Minsta inkomst (minsta enheter)',
        'income_help' => 'Inkomster under det här tröskelvärdet grupperas inte automatiskt. Lagras i minsta enheter — :minor betyder :example. Sätt till 0 för att stänga av tröskelvärdet.',
    ],

    'drift' => [
        'heading' => 'Avvikelsevarningar',
        'label' => 'Standardtröskel för avvikelsevarningar',
        'help' => 'Varningar utlöses när det senaste beloppet för en återkommande debitering skiljer sig från föregående belopp med mer än den här procentsatsen. Inställningar per serie har företräde.',
        'options' => [
            '1' => '±1 %',
            '2' => '±2 %',
            '5' => '±5 % (standard)',
            '10' => '±10 %',
            '25' => '±25 %',
            '50' => '±50 %',
        ],
    ],

    'save' => 'Spara inställningar',
    'saved' => 'Sparat.',

    'anomaly_heading' => 'Anomalidetektering',
    'notifications_heading' => 'Notiser',

    'forecasting' => [
        'heading' => 'Prognoser',
        'intro' => 'Beatrax projicerar ditt saldo framåt utifrån kontonas aktuella läge. För konton utan saldo från kontoutdrag (PayPal, äldre CSV-importer) anger du det ingående saldot här, så att prognoserna utgår från en känd punkt.',
        'no_accounts' => 'Inga konton än — importera ett kontoutdrag för att lägga till ett.',
    ],

    'auto_import' => [
        'heading' => 'Automatisk import',
        'label' => 'Automatisk import från släppmappen',

        'active_html' => 'Släppmappen är aktiv. Beatrax genomsöker <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> var femte minut efter nya filer.',
        'inactive_html' => 'När funktionen är på genomsöker Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> var femte minut efter <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- och <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-filer och importerar dem genom samma matchningskedja som guiden. Behandlade filer flyttas till <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> så att de aldrig importeras två gånger.',
        'active_phone_html' => 'Släppmappen är aktiv. Beatrax genomsöker <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> i bakgrunden efter nya filer. Din telefon bestämmer när en bakgrundssökning körs, så det kan ta minuter eller timmar.',
        'inactive_phone_html' => 'När funktionen är på genomsöker Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> i bakgrunden efter <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- och <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-filer och importerar dem genom samma matchningskedja som guiden. Din telefon bestämmer när en bakgrundssökning körs, så det kan ta minuter eller timmar. Behandlade filer flyttas till <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> så att de aldrig importeras två gånger.',
    ],

    'aliases' => [
        'heading' => 'Alias',
        'intro' => 'Granska och redigera de begripliga namn du har lärt Beatrax för kryptiska beskrivningar i kontoutdrag.',
        'manage' => 'Hantera alias →',
    ],

    'tax_heading' => 'Skatt',
    'data_backup_heading' => 'Data & säkerhetskopiering',

    'about_updates' => [
        'heading' => 'Om uppdateringar',
        'body' => 'Beatrax uppdaterar sig själv automatiskt när appen väl är installerad. Efter att du installerat den allra första versionen kommer kommande versioner via en banner i appen — du behöver inte gå tillbaka till GitHub. Skulle en framtida uppdatering någon gång misslyckas kan du alltid ladda ner det senaste installationsprogrammet manuellt från releasesidan.',
        'body_phone' => 'Här uppdaterar Beatrax inte sig själv. Nya versioner av telefonappen kommer via App Store eller Google Play, precis som dina andra appar.',
        'check_label' => 'Sök efter uppdateringar automatiskt',
        'check_on' => 'Beatrax frågar releaseflödet om det finns en nyare signerad version. Ingenting laddas ner förrän du själv väljer att installera den.',
        'check_off' => 'Det söks inte efter uppdateringar och ingenting lämnar den här enheten. Nya versioner hittar du genom att själv öppna releasesidan.',
        'open_releases' => 'Öppna releasesidan →',
    ],

    'privacy' => [
        'heading' => 'Integritetspolicy',
        'body' => 'Beatrax håller din ekonomi på dina egna enheter. Policyn förklarar vad det innebär, vad de valfria onlinefunktionerna skickar och hur du tar bort dina uppgifter.',
        'open' => 'Läs integritetspolicyn →',
        'url_hint' => 'Om länken inte öppnas, gå till:',
    ],

    'first_run_tour' => [
        'heading' => 'Rundtur vid första starten',
        'body' => 'Starta installationsguiden igen om du vill gå igenom introduktionen en gång till.',
        'run_again' => 'Kör installationsguiden igen',
    ],

    'developer' => [
        'heading' => 'Utvecklare',
        'label' => 'Utvecklarkonsol i appen',
        'help' => 'Visa utvecklarkonsolen på /dev. Återställer växeln Avancerat vid varje inloggning.',
        'aria' => 'Utvecklarläge',
    ],

    'errors' => [
        'period_move_failed' => 'Budgetmånaden kunde inte flyttas, så den blev kvar där den var.',
        'currency_required' => 'Välj en valuta.',
        'window_months' => 'Välj mellan 2 och 60 månader.',
        'threshold' => 'Välj ett tröskelvärde på 1 %, 2 %, 5 %, 10 %, 25 % eller 50 %.',
        'amount' => 'Ange ett belopp från :zero och uppåt.',
        'period_day' => 'Välj en dag från 1 till 28.',
        'currency_view' => 'Välj ett av de tillgängliga alternativen.',
        'timezone' => 'Välj en tidszon i listan.',
    ],
];
