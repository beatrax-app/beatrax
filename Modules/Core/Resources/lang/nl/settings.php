<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Weergave',
        'money' => 'Geld',
        'insights' => 'Inzichten & meldingen',
        'security' => 'Beveiliging & apparaten',
        'data' => 'Imports & gegevens',
        'app' => 'App',
    ],

    'title' => 'Instellingen',
    'subtitle' => 'Voorkeuren voor hoe je financiën in de app worden weergegeven.',

    'appearance' => [
        'heading' => 'Weergave',
        'theme' => 'Thema',
        'theme_light' => 'Licht',
        'theme_dark' => 'Donker',
        'theme_system' => 'Systeem',
        'theme_help' => 'Systeem volgt de licht- of donkerinstelling van je besturingssysteem.',
    ],

    'language' => [
        'apply' => 'Toepassen',
        'heading' => 'Taal',
        'label' => 'Weergavetaal',
        'system' => 'Systeem',
        'help' => 'Verandert de woorden op het scherm en hoe bedragen worden geschreven. Systeem volgt de taal van je browser of besturingssysteem, met Engels als standaard.',
    ],

    'country' => [
        'heading' => 'Land',
        'label' => 'Jouw land',
        'help' => 'Bepaalt van welk land de app de belastingregels, overheidsinstanties en bankkosten herkent. Het verandert de taal niet en ook niet hoe bedragen worden geschreven.',
        'choose' => 'Kies een land…',
        'switch_note' => 'Wisselen voegt nieuwe categorieën toe — bestaande tags worden nooit gewijzigd.',

        'wording_note' => 'Namen van belastingcategorieën komen uit de aangifte die in :country wordt gebruikt en blijven dus in de woorden van dat land, in elke apptaal.',

        'countries' => [
            'at' => 'Oostenrijk',
            'be' => 'België',
            'bg' => 'Bulgarije',
            'ca' => 'Canada',
            'ch' => 'Zwitserland',
            'cy' => 'Cyprus',
            'cz' => 'Tsjechië',
            'de' => 'Duitsland',
            'dk' => 'Denemarken',
            'ee' => 'Estland',
            'es' => 'Spanje',
            'fi' => 'Finland',
            'fr' => 'Frankrijk',
            'gb' => 'Verenigd Koninkrijk',
            'gr' => 'Griekenland',
            'hr' => 'Kroatië',
            'hu' => 'Hongarije',
            'ie' => 'Ierland',
            'is' => 'IJsland',
            'it' => 'Italië',
            'lt' => 'Litouwen',
            'lu' => 'Luxemburg',
            'lv' => 'Letland',
            'mt' => 'Malta',
            'nl' => 'Nederland',
            'no' => 'Noorwegen',
            'pl' => 'Polen',
            'pt' => 'Portugal',
            'ro' => 'Roemenië',
            'se' => 'Zweden',
            'si' => 'Slovenië',
            'sk' => 'Slowakije',
            'us' => 'Verenigde Staten',
        ],
    ],

    'currency_display' => [
        'heading' => 'Valutaweergave',
        'label' => 'Standaardweergave in de transactielijst',
        'eur_only' => 'Alleen :code',
        'original' => 'Oorspronkelijke valuta',
        'help' => 'Je kunt dit nog steeds per pagina wijzigen in de transactielijst.',
    ],

    'base_currency' => [
        'heading' => 'Basisrapportagevaluta',
        'label' => 'Rapportagevaluta',
        'help' => 'Alle totalen en samenvattingen worden naar deze valuta omgerekend. Elke rekening toont daarnaast nog steeds zijn eigen oorspronkelijke valuta.',
    ],

    'exchange_rates' => [
        'heading' => 'Wisselkoersen',
        'fetch_online' => 'Actuele koersen online ophalen',
        'online_on' => 'Koersen worden dagelijks bij de ECB opgehaald. Alleen valutaparen — geen persoonlijke gegevens.',
        'last_updated' => 'Laatst bijgewerkt: :date.',
        'online_off' => 'Er worden meegeleverde koersen gebruikt. Er verlaten geen gegevens je apparaat.',
        'fetch_aria' => 'Actuele wisselkoersen online ophalen',
        'refreshing' => 'Bezig met vernieuwen…',
        'next_refresh' => 'Volgende automatische vernieuwing: dagelijks om 09:00',
        'refresh_gave_up' => 'Kon de koersen niet vernieuwen. De koersen die al op dit apparaat staan, worden nog gebruikt.',
        'refresh_now' => 'Nu vernieuwen',
    ],

    'period' => [
        'heading' => 'Periode',
        'label' => 'Periode begint op dag',
        'help' => 'Genummerd van 1 tot 28. De meeste gebruikers houden dit op 1 (kalendermaand). Gebruik 25 als je salaris op de 25e binnenkomt en je "jouw maand" dan laat beginnen.',
    ],

    'recurring' => [
        'heading' => 'Detectie van terugkerende betalingen',
        'window_label' => 'Detectievenster (maanden)',
        'window_help' => 'Hoeveel maanden geschiedenis worden gescand bij het clusteren van transacties tot terugkerende patronen.',
        'income_label' => 'Minimuminkomen (centen)',
        'income_help' => 'Inkomsten onder deze drempel worden niet automatisch geclusterd. Opgeslagen in centen — 200000 betekent € 2.000,00. Zet op 0 om de drempel uit te schakelen.',
    ],

    'drift' => [
        'heading' => 'Afwijkingswaarschuwingen',
        'label' => 'Standaarddrempel voor afwijkingswaarschuwingen',
        'help' => 'Waarschuwingen verschijnen wanneer het laatste bedrag van een terugkerende afschrijving meer dan dit percentage afwijkt van het vorige bedrag. Instellingen per reeks hebben voorrang.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (standaard)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Instellingen opslaan',
    'saved' => 'Opgeslagen.',

    'anomaly_heading' => 'Anomaliedetectie',
    'notifications_heading' => 'Meldingen',

    'forecasting' => [
        'heading' => 'Prognoses',
        'intro' => 'Beatrax projecteert je saldo vooruit vanaf de huidige stand van je rekeningen. Voor rekeningen zonder afschriftsaldo (PayPal, oude CSV-imports) stel je hier het beginsaldo in, zodat prognoses vanaf een bekend punt starten.',
        'no_accounts' => 'Nog geen rekeningen — importeer een afschrift om er een toe te voegen.',
    ],

    'auto_import' => [
        'heading' => 'Automatisch importeren',
        'label' => 'Automatisch importeren uit de neerzetmap',
        'active_html' => 'De neerzetmap is actief. Beatrax scant <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> elke 5 minuten op nieuwe bestanden.',
        'inactive_html' => 'Indien ingeschakeld scant Beatrax <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> elke 5 minuten op <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code>- en <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code>-bestanden en importeert deze via dezelfde matcher-pijplijn als de wizard. Verwerkte bestanden worden verplaatst naar <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> zodat ze nooit dubbel worden geïmporteerd.',
    ],

    'aliases' => [
        'heading' => 'Aliassen',
        'intro' => 'Bekijk en bewerk de herkenbare namen die je Beatrax hebt geleerd voor cryptische afschriftomschrijvingen.',
        'manage' => 'Aliassen beheren →',
    ],

    'tax_heading' => 'Belasting',
    'shared_merchant_heading' => 'Gedeelde winkelierslijst',
    'data_backup_heading' => 'Gegevens & back-up',
    'install_heading' => 'Installeren',

    'about_updates' => [
        'heading' => 'Over updates',
        'body' => 'Beatrax werkt zichzelf automatisch bij zodra het is geïnstalleerd. Na het installeren van de allereerste versie komen toekomstige versies binnen via een banner in de app — je hoeft GitHub niet opnieuw te bezoeken. Mocht een toekomstige update ooit niet lukken, dan kun je altijd handmatig de nieuwste installer downloaden van de releasespagina.',
        'open_releases' => 'Releasespagina openen →',
    ],

    'privacy' => [
        'heading' => 'Privacybeleid',
        'body' => 'Beatrax houdt je financiën op je eigen apparaten. Het beleid legt uit wat dat betekent, wat de optionele online functies versturen en hoe je je gegevens verwijdert.',
        'open' => 'Privacybeleid lezen →',
        'url_hint' => 'Als de link niet opent, ga naar:',
    ],

    'first_run_tour' => [
        'heading' => 'Rondleiding voor de eerste keer',
        'body' => 'Start de installatiewizard opnieuw als je de introductie nog eens wilt doorlopen.',
        'run_again' => 'Installatiewizard opnieuw uitvoeren',
    ],

    'developer' => [
        'heading' => 'Ontwikkelaar',
        'label' => 'Dev Console in de app',
        'help' => 'Toon de Dev Console op /dev. Zet de geavanceerde schakelaar bij elke aanmelding terug.',
        'aria' => 'Ontwikkelaarsmodus',
    ],

    'errors' => [
        'currency_required' => 'Kies een valuta.',
        'window_months' => 'Kies tussen 2 en 60 maanden.',
        'threshold' => 'Kies een drempel van 1%, 2%, 5%, 10%, 25% of 50%.',
        'amount' => 'Voer een bedrag in vanaf € 0.',
        'period_day' => 'Kies een dag van 1 tot 28.',
        'currency_view' => 'Kies een van de beschikbare opties.',
    ],
];
