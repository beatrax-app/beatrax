<?php

declare(strict_types=1);

return [
    'heading' => 'Inkorgar',
    'intro' => 'Anslut inkorgar från Gmail och Microsoft 365 så att Beatrax kan söka igenom dem efter kvitton.',

    'connection_canceled' => 'Anslutningen avbröts.',
    'connection_failed' => 'Det gick inte att slutföra anslutningen.',

    'backfilling' => 'Hämtar historik',
    'messages_suffix' => 'meddelanden',

    'connect_heading' => 'Anslut din e-post',
    'connect_body' => 'Importera kvitton från PayPal, ICS Cards, Google Play och andra handlare genom att ge Beatrax skrivskyddad åtkomst till en eller flera av dina inkorgar.',
    'connect_gmail' => 'Anslut Gmail',
    'connect_microsoft' => 'Anslut Microsoft 365',
    'readonly_note' => 'Beatrax läser bara meddelanden. Det skickar, etiketterar, flyttar eller raderar aldrig något i din inkorg.',

    'month' => '1 månad',
    'months' => ':count månader',
    'not_scanned_yet' => 'inte skannad än',
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

    'retry_seconds' => 'nytt försök om :ns',
    'retry_minutes' => 'nytt försök om :nmin',
    'retry_hours' => 'nytt försök om :ntim',

    'reconnect' => 'Anslut på nytt',
    'scan_now' => 'Skanna nu',
    'scan_in_progress_title' => 'Skanning pågår redan',

    'add_another' => 'Lägg till en inkorg till',
    'gmail_card_body' => 'Anslut ett Gmail-konto så att Beatrax kan söka igenom det efter kvitton.',
    'microsoft_card_body' => 'Anslut ett Microsoft 365- eller Outlook.com-konto så att Beatrax kan söka igenom det efter kvitton.',

    'discovered_heading' => 'Upptäckta avsändare',
    'discovered_body' => 'Avsändare som ser ut att skicka kvitton men som ännu inte finns på din lista över kända kvittoavsändare. Lägg till dem du vill att Beatrax ska skanna och stäng resten.',
    'last_seen' => 'senast sedd',
    'seen_times' => 'Sedd :count gånger',
    'add' => 'Lägg till',
    'add_aria' => 'Lägg till :email',
    'dismiss' => 'Stäng',
    'dismiss_aria' => 'Stäng :email',

    'toast' => [
        'scan_in_progress' => 'Skanning pågår redan.',
        'scan_started' => 'Skanningen har startat.',
        'sender_added' => 'Avsändaren har lagts till.',
        'sender_dismissed' => 'Avsändaren har stängts.',
    ],
];
