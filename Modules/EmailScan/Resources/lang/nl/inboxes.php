<?php

declare(strict_types=1);

return [
    'heading' => 'Postvakken',
    'intro' => 'Koppel Gmail- en Microsoft 365-postvakken zodat Beatrax ze op bonnen kan scannen.',
    'intro_phone' => 'Postvakscannen gebeurt in de desktop-app, niet op deze telefoon.',

    'phone_heading' => 'Deze telefoon scant geen postvakken',
    'phone_body' => 'Koppel Gmail of Microsoft 365 in de desktop-app — de bonnen die daar gevonden worden komen hier via synchronisatie binnen.',
    'connection_canceled' => 'Verbinding geannuleerd.',
    'connection_failed' => 'Kon de verbinding niet voltooien.',

    'backfilling' => 'Bezig met ophalen',
    'backfill_progress' => ':fetched / ~:count bericht|:fetched / ~:count berichten',

    'connect_heading' => 'Koppel je e-mail',
    'connect_body' => 'Importeer bonnen van PayPal, ICS Cards, Google Play en andere winkeliers door Beatrax alleen-lezen toegang te geven tot een of meer van je postvakken.',
    'connect_body_phone' => 'Bonnen van PayPal, ICS Cards, Google Play en andere winkeliers worden geïmporteerd door de desktop-app, uit de postvakken waartoe je die alleen-lezen toegang geeft. Deze telefoon laat zien wat die import vindt.',
    'connect_gmail' => 'Gmail koppelen',
    'connect_microsoft' => 'Microsoft 365 koppelen',
    'readonly_note' => 'Beatrax leest alleen berichten. Het verstuurt, labelt, verplaatst of verwijdert nooit iets in je postvak.',

    'months' => ':count maand|:count maanden',
    'not_scanned_yet' => 'nog niet gescand',
    'not_scanned_yet_phone' => 'niet gescand op deze telefoon',
    'last_scanned' => 'laatst gescand',
    'window_prefix' => 'Periode:',
    'edit' => 'Bewerken',

    'badge' => [
        'idle' => 'Inactief',
        'backfilling' => 'Ophalen',
        'scanning' => 'Scannen',
        'rate_limited' => 'Snelheid beperkt',
        'needs_reauth' => 'Opnieuw verifiëren',
        'error' => 'Fout',
    ],

    'error_detail' => 'De laatste scan is niet voltooid. Probeer Nu scannen of verbind dit postvak opnieuw.',
    'oauth_state_mismatch' => 'Deze koppelingslink is verlopen of al gebruikt. Start de koppeling opnieuw.',
    'oauth_client_missing' => 'De eenmalige installatie voor die e-mailprovider is op dit apparaat niet afgerond, dus er is nog niets om mee te koppelen. Druk opnieuw op Koppelen om die af te ronden.',
    'oauth_no_code' => 'Je mailprovider stuurde je terug zonder de code die Beatrax nodig heeft om af te ronden, dus er is geen postvak gekoppeld. Start de koppeling opnieuw.',
    'oauth_grant_refused' => 'Je mailprovider weigerde de toestemming die Beatrax had gekregen — die is verlopen of ingetrokken. Start de koppeling opnieuw en geef toestemming.',
    'oauth_exchange_failed' => 'Je mailprovider heeft de koppeling niet afgerond, dus er is geen postvak toegevoegd. Probeer het over een paar minuten opnieuw.',
    'oauth_not_saved' => 'De koppeling kon niet op dit apparaat worden opgeslagen, dus er is geen postvak toegevoegd. Probeer het opnieuw — blijft het misgaan, dan staat in het app-logboek wat het tegenhield.',
    'oauth_no_offline_access_google' => 'Google gaf niet de blijvende toestemming die Beatrax nodig heeft, dus dit postvak zou binnen een uur stoppen met scannen. Zet je OAuth-toestemmingsscherm op productie en koppel daarna opnieuw.',
    'oauth_no_offline_access' => 'Je mailprovider gaf niet de blijvende toestemming die Beatrax nodig heeft, dus dit postvak zou binnen een uur stoppen met scannen. Koppel opnieuw en sta offline toegang toe wanneer daarom wordt gevraagd.',
    'oauth_no_offline_access_google_phone' => 'Google gaf niet de blijvende toestemming die Beatrax nodig heeft, dus er is geen postvak gekoppeld. Zet je OAuth-toestemmingsscherm op productie en koppel daarna opnieuw — het scannen zelf gebeurt in de desktop-app.',
    'oauth_no_offline_access_phone' => 'Je mailprovider gaf niet de blijvende toestemming die Beatrax nodig heeft, dus er is geen postvak gekoppeld. Koppel opnieuw en sta offline toegang toe wanneer daarom wordt gevraagd — het scannen zelf gebeurt in de desktop-app.',

    'retry_seconds' => 'opnieuw over :ns',
    'retry_minutes' => 'opnieuw over :nm',
    'retry_hours' => 'opnieuw over :nu',

    'reconnect' => 'Opnieuw verbinden',
    'disconnect' => 'Ontkoppelen',
    'scan_now' => 'Nu scannen',
    'scan_in_progress_title' => 'Er loopt al een scan',

    'add_another' => 'Nog een postvak toevoegen',
    'gmail_card_body' => 'Koppel een Gmail-account zodat Beatrax het op bonnen kan scannen.',
    'microsoft_card_body' => 'Koppel een Microsoft 365- of Outlook.com-account zodat Beatrax het op bonnen kan scannen.',
    'gmail_card_body_phone' => 'Gmail wordt gescand door de desktop-app. Een account dat je hier koppelt wordt nooit vanzelf gescand.',
    'microsoft_card_body_phone' => 'Microsoft 365 en Outlook.com worden gescand door de desktop-app. Een account dat je hier koppelt wordt nooit vanzelf gescand.',

    'discovered_heading' => 'Ontdekte afzenders',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (afschriften)',
    ],
    'discovered_body' => 'Afzenders die eruitzien alsof ze bonnen sturen maar nog niet op je lijst met bekende bonnen staan. Voeg de afzenders toe die je door Beatrax wilt laten scannen; wijs de rest af.',
    'last_seen' => 'laatst gezien',
    'seen_times' => ':count keer gezien|:count keer gezien',
    'add' => 'Toevoegen',
    'add_aria' => ':email toevoegen',
    'dismiss' => 'Afwijzen',
    'dismiss_aria' => ':email afwijzen',

    'toast' => [
        'reconnect_first' => 'Verbind deze inbox opnieuw voordat je scant.',
        'scan_in_progress' => 'Er loopt al een scan.',
        'scan_started' => 'Scan gestart.',
        'sender_added' => 'Afzender toegevoegd.',
        'sender_dismissed' => 'Afzender afgewezen.',
    ],
];
