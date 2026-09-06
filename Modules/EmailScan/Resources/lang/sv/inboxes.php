<?php

declare(strict_types=1);

return [
    'heading' => 'Inkorgar',
    'intro' => 'Anslut inkorgar från Gmail och Microsoft 365 så att Beatrax kan söka igenom dem efter kvitton.',
    'intro_phone' => 'Genomsökning av inkorgar sker i skrivbordsappen, inte på den här telefonen.',

    'phone_heading' => 'Den här telefonen söker inte igenom brevlådor',
    'phone_body' => 'Koppla Gmail eller Microsoft 365 i skrivbordsappen — kvittona den hittar kommer hit via synkronisering.',
    'connection_canceled' => 'Anslutningen avbröts.',
    'connection_failed' => 'Det gick inte att slutföra anslutningen.',

    'backfilling' => 'Hämtar historik',
    'backfill_progress' => ':fetched / ~:count meddelande|:fetched / ~:count meddelanden',

    'connect_heading' => 'Anslut din e-post',
    'connect_body' => 'Importera kvitton från PayPal, ICS Cards, Google Play och andra handlare genom att ge Beatrax skrivskyddad åtkomst till en eller flera av dina inkorgar.',
    'connect_body_phone' => 'Kvitton från PayPal, ICS Cards, Google Play och andra handlare importeras av skrivbordsappen, från de inkorgar du ger den skrivskyddad åtkomst till. Den här telefonen visar vad den importen hittar.',
    'connect_gmail' => 'Anslut Gmail',
    'connect_microsoft' => 'Anslut Microsoft 365',
    'readonly_note' => 'Beatrax läser bara meddelanden. Det skickar, etiketterar, flyttar eller raderar aldrig något i din inkorg.',

    'months' => ':count månad|:count månader',
    'not_scanned_yet' => 'inte skannad än',
    'not_scanned_yet_phone' => 'inte skannad på den här telefonen',
    'last_scanned' => 'senast skannad',
    'window_prefix' => 'Period:',
    'edit' => 'Redigera',

    'badge' => [
        'idle' => 'Inaktiv',
        'backfilling' => 'Hämtar historik',
        'scanning' => 'Skannar',
        'rate_limited' => 'Hastighetsbegränsad',
        'needs_reauth' => 'Kräver ny inloggning',
        'error' => 'Fel',
    ],

    'error_detail' => 'Den senaste skanningen slutfördes inte. Prova Skanna nu eller anslut den här inkorgen på nytt.',
    'oauth_state_mismatch' => 'Den här anslutningslänken har gått ut eller är redan använd. Börja om kopplingen.',
    'oauth_client_missing' => 'Engångskonfigurationen för den e-postleverantören är inte klar på den här enheten, så det finns ännu inget att ansluta med. Tryck på Anslut igen för att slutföra den.',
    'oauth_no_code' => 'Din e-postleverantör skickade tillbaka dig utan koden Beatrax behöver för att slutföra, så ingen brevlåda kopplades. Börja om kopplingen.',
    'oauth_grant_refused' => 'Din e-postleverantör nekade behörigheten Beatrax fått — den har gått ut eller dragits tillbaka. Börja om kopplingen och godkänn den.',
    'oauth_exchange_failed' => 'Din e-postleverantör slutförde inte kopplingen, så ingen brevlåda lades till. Försök igen om några minuter.',
    'oauth_not_saved' => 'Kopplingen gick inte att spara på den här enheten, så ingen brevlåda lades till. Försök igen — om det fortsätter att misslyckas noterar appens logg vad som stoppade den.',
    'oauth_no_offline_access_google' => 'Google gav inte den varaktiga behörighet Beatrax behöver, så den här brevlådan skulle sluta genomsökas inom en timme. Publicera din OAuth-medgivandeskärm till produktion och koppla sedan igen.',
    'oauth_no_offline_access' => 'Din e-postleverantör gav inte den varaktiga behörighet Beatrax behöver, så den här brevlådan skulle sluta genomsökas inom en timme. Koppla igen och tillåt offlineåtkomst när du blir tillfrågad.',
    'oauth_no_offline_access_google_phone' => 'Google gav inte den varaktiga behörighet Beatrax behöver, så ingen brevlåda kopplades. Publicera din OAuth-medgivandeskärm till produktion och koppla sedan igen — själva genomsökningen sker i skrivbordsappen.',
    'oauth_no_offline_access_phone' => 'Din e-postleverantör gav inte den varaktiga behörighet Beatrax behöver, så ingen brevlåda kopplades. Koppla igen och tillåt offlineåtkomst när du blir tillfrågad — själva genomsökningen sker i skrivbordsappen.',

    'retry_seconds' => 'nytt försök om :ns',
    'retry_minutes' => 'nytt försök om :nmin',
    'retry_hours' => 'nytt försök om :ntim',

    'reconnect' => 'Anslut på nytt',
    'disconnect' => 'Koppla från',
    'disconnect_confirm' => 'Vill du koppla från :email? Detta tar bort de sparade uppgifterna för den här brevlådan, dess skanningshistorik och de avsändare du har lagt till eller stängt. Kvitton som redan är införda i Beatrax påverkas inte. Ansluter du på nytt startar en ny skanning från början.',
    'scan_now' => 'Skanna nu',
    'scan_in_progress_title' => 'Skanning pågår redan',

    'add_another' => 'Lägg till en inkorg till',
    'gmail_card_body' => 'Anslut ett Gmail-konto så att Beatrax kan söka igenom det efter kvitton.',
    'microsoft_card_body' => 'Anslut ett Microsoft 365- eller Outlook.com-konto så att Beatrax kan söka igenom det efter kvitton.',
    'gmail_card_body_phone' => 'Gmail söks igenom av skrivbordsappen. Anslut kontot där — den här telefonen visar vad den hittar.',
    'microsoft_card_body_phone' => 'Microsoft 365 och Outlook.com söks igenom av skrivbordsappen. Anslut kontona där — den här telefonen visar vad den hittar.',

    'discovered_heading' => 'Upptäckta avsändare',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (kontoutdrag)',
    ],
    'discovered_body' => 'Avsändare som ser ut att skicka kvitton men som ännu inte finns på din lista över kända kvittoavsändare. Lägg till dem du vill att Beatrax ska skanna och stäng resten.',
    'last_seen' => 'senast sedd',
    'seen_times' => 'Sedd :count gång|Sedd :count gånger',
    'add' => 'Lägg till',
    'add_aria' => 'Lägg till :email',
    'dismiss' => 'Stäng',
    'dismiss_aria' => 'Stäng :email',

    'toast' => [
        'reconnect_first' => 'Anslut den här inkorgen igen innan du skannar.',
        'scan_in_progress' => 'Skanning pågår redan.',
        'scan_started' => 'Skanningen har startat.',
        'sender_added' => 'Avsändaren har lagts till.',
        'sender_dismissed' => 'Avsändaren har stängts.',
    ],
];
