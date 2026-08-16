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
        'help' => 'System följer språket i din webbläsare eller ditt operativsystem, med engelska som standard.',
    ],

    'currency_display' => [
        'heading' => 'Valutavisning',
        'label' => 'Standardvy i transaktionslistan',
        'eur_only' => 'Endast EUR',
        'original' => 'Ursprunglig valuta',
        'help' => 'Du kan fortfarande växla per sida från transaktionslistan.',
    ],

    'base_currency' => [
        'heading' => 'Basvaluta för rapportering',
        'label' => 'Rapporteringsvaluta',
        'help' => 'Alla summor och sammanställningar räknas om till den här valutan. Varje konto visar fortfarande sin egen ursprungliga valuta bredvid.',
    ],

    'exchange_rates' => [
        'heading' => 'Växelkurser',
        'fetch_online' => 'Hämta aktuella kurser online',
        'online_on' => 'Kurser hämtas dagligen från ECB. Endast uppslag av valutapar — inga personuppgifter.',
        'last_updated' => 'Senast uppdaterad: :date.',
        'online_off' => 'Medföljande kurser används. Inga data lämnar den här enheten.',
        'fetch_aria' => 'Hämta aktuella växelkurser online',
        'refreshing' => 'Uppdaterar…',
        'next_refresh' => 'Nästa automatiska uppdatering: dagligen kl. 09:00',
        'refresh_now' => 'Uppdatera nu',
    ],

    'period' => [
        'heading' => 'Period',
        'label' => 'Perioden börjar dag',
        'help' => 'Numrerat 1 till 28. De flesta har kvar 1 (kalendermånad). Använd 25 om lönen kommer den 25 varje månad och du tänker på "din månad" som att den börjar då.',
    ],

    'recurring' => [
        'heading' => 'Detektering av återkommande betalningar',
        'window_label' => 'Detekteringsfönster (månader)',
        'window_help' => 'Hur många månaders historik som genomsöks när transaktioner grupperas till återkommande mönster.',
        'income_label' => 'Minsta inkomst (cent)',
        'income_help' => 'Inkomster under det här tröskelvärdet grupperas inte automatiskt. Lagras i cent — 200000 betyder 2 000,00 €. Sätt till 0 för att stänga av tröskelvärdet.',
    ],

    'drift' => [
        'heading' => 'Avvikelsevarningar',
        'label' => 'Standardtröskel för avvikelsevarningar',
        'help' => 'Varningar utlöses när det senaste beloppet för en återkommande debitering skiljer sig från föregående belopp med mer än den här procentsatsen. Inställningar per serie har företräde.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (standard)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
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
    ],

    'aliases' => [
        'heading' => 'Alias',
        'intro' => 'Granska och redigera de begripliga namn du har lärt Beatrax för kryptiska beskrivningar i kontoutdrag.',
        'manage' => 'Hantera alias →',
    ],

    'tax_heading' => 'Skatt',
    'shared_merchant_heading' => 'Delad handlarlista',
    'data_backup_heading' => 'Data & säkerhetskopiering',
    'install_heading' => 'Installera',

    'about_updates' => [
        'heading' => 'Om uppdateringar',
        'body' => 'Beatrax uppdaterar sig själv automatiskt när appen väl är installerad. Efter att du installerat den allra första versionen kommer kommande versioner via en banner i appen — du behöver inte gå tillbaka till GitHub. Skulle en framtida uppdatering någon gång misslyckas kan du alltid ladda ner det senaste installationsprogrammet manuellt från releasesidan.',
        'open_releases' => 'Öppna releasesidan →',
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
        'currency_required' => 'Välj en valuta.',
        'window_months' => 'Välj mellan 2 och 60 månader.',
        'threshold' => 'Välj ett tröskelvärde på 1%, 2%, 5%, 10%, 25% eller 50%.',
        'amount' => 'Ange ett belopp från €0 och uppåt.',
        'period_day' => 'Välj en dag från 1 till 28.',
        'currency_view' => 'Välj ett av de tillgängliga alternativen.',
    ],
];
