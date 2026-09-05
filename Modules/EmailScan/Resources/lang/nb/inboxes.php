<?php

declare(strict_types=1);

return [
    'heading' => 'Innbokser',
    'intro' => 'Koble til innbokser fra Gmail og Microsoft 365 slik at Beatrax kan skanne dem for kvitteringer.',
    'intro_phone' => 'Skanning av innbokser skjer i skrivebordsappen, ikke på denne telefonen.',

    'phone_heading' => 'Denne telefonen skanner ikke postkasser',
    'phone_body' => 'Koble til Gmail eller Microsoft 365 i skrivebordsappen — kvitteringene den finner kommer hit via synkronisering.',
    'connection_canceled' => 'Tilkoblingen ble avbrutt.',
    'connection_failed' => 'Tilkoblingen kunne ikke fullføres.',

    'backfilling' => 'Henter historikk',
    'backfill_progress' => ':fetched / ~:count melding|:fetched / ~:count meldinger',

    'connect_heading' => 'Koble til e-posten din',
    'connect_body' => 'Importer kvitteringer fra PayPal, ICS Cards, Google Play og andre forhandlere ved å gi Beatrax skrivebeskyttet tilgang til en eller flere av innboksene dine.',
    'connect_body_phone' => 'Kvitteringer fra PayPal, ICS Cards, Google Play og andre forhandlere importeres av skrivebordsappen, fra innboksene du gir den skrivebeskyttet tilgang til. Denne telefonen viser hva den importen finner.',
    'connect_gmail' => 'Koble til Gmail',
    'connect_microsoft' => 'Koble til Microsoft 365',
    'readonly_note' => 'Beatrax leser bare meldinger. Det sender, merker, flytter eller sletter aldri noe i innboksen din.',

    'months' => ':count måned|:count måneder',
    'not_scanned_yet' => 'ikke skannet ennå',
    'not_scanned_yet_phone' => 'ikke skannet på denne telefonen',
    'last_scanned' => 'sist skannet',
    'window_prefix' => 'Periode:',
    'edit' => 'Rediger',

    'badge' => [
        'idle' => 'Inaktiv',
        'backfilling' => 'Henter historikk',
        'scanning' => 'Skanner',
        'rate_limited' => 'Hastighetsbegrenset',
        'needs_reauth' => 'Krever ny godkjenning',
        'error' => 'Feil',
    ],

    'error_detail' => 'Den siste skanningen ble ikke fullført. Prøv Skann nå, eller koble til denne innboksen på nytt.',
    'oauth_state_mismatch' => 'Denne tilkoblingslenken er utløpt eller allerede brukt. Start tilkoblingen på nytt.',
    'oauth_client_missing' => 'Engangsoppsettet for den e-postleverandøren er ikke fullført på denne enheten, så det finnes ennå ingenting å koble til med. Trykk Koble til igjen for å fullføre det.',
    'oauth_no_code' => 'E-postleverandøren din sendte deg tilbake uten koden Beatrax trenger for å fullføre, så ingen postkasse ble koblet til. Start tilkoblingen på nytt.',
    'oauth_grant_refused' => 'E-postleverandøren din avviste tillatelsen Beatrax hadde fått — den er utløpt eller trukket tilbake. Start tilkoblingen på nytt og godkjenn den.',
    'oauth_exchange_failed' => 'E-postleverandøren din fullførte ikke tilkoblingen, så ingen postkasse ble lagt til. Prøv igjen om noen minutter.',
    'oauth_not_saved' => 'Tilkoblingen kunne ikke lagres på denne enheten, så ingen postkasse ble lagt til. Prøv igjen — fortsetter den å feile, noterer apploggen hva som stoppet den.',
    'oauth_no_offline_access_google' => 'Google ga ikke den varige tillatelsen Beatrax trenger, så denne postkassen ville slutte å bli skannet innen en time. Publiser OAuth-samtykkeskjermen din til produksjon, og koble til på nytt.',
    'oauth_no_offline_access' => 'E-postleverandøren din ga ikke den varige tillatelsen Beatrax trenger, så denne postkassen ville slutte å bli skannet innen en time. Koble til på nytt og tillat frakoblet tilgang når du blir spurt.',
    'oauth_no_offline_access_google_phone' => 'Google ga ikke den varige tillatelsen Beatrax trenger, så ingen postkasse ble koblet til. Publiser OAuth-samtykkeskjermen din til produksjon, og koble til på nytt — selve skanningen skjer i skrivebordsappen.',
    'oauth_no_offline_access_phone' => 'E-postleverandøren din ga ikke den varige tillatelsen Beatrax trenger, så ingen postkasse ble koblet til. Koble til på nytt og tillat frakoblet tilgang når du blir spurt — selve skanningen skjer i skrivebordsappen.',

    'retry_seconds' => 'nytt forsøk om :ns',
    'retry_minutes' => 'nytt forsøk om :nmin',
    'retry_hours' => 'nytt forsøk om :nt',

    'reconnect' => 'Koble til på nytt',
    'disconnect' => 'Koble fra',
    'disconnect_confirm' => 'Vil du koble fra :email? Dette fjerner de lagrede opplysningene for denne postkassen, skannehistorikken og avsenderne du har lagt til eller lukket. Kvitteringer som allerede er ført inn i Beatrax, påvirkes ikke. Kobler du til på nytt, starter en ny skanning fra bunnen.',
    'scan_now' => 'Skann nå',
    'scan_in_progress_title' => 'Skanning pågår allerede',

    'add_another' => 'Legg til en innboks til',
    'gmail_card_body' => 'Koble til en Gmail-konto slik at Beatrax kan skanne den for kvitteringer.',
    'microsoft_card_body' => 'Koble til en Microsoft 365- eller Outlook.com-konto slik at Beatrax kan skanne den for kvitteringer.',
    'gmail_card_body_phone' => 'Gmail skannes av skrivebordsappen. En konto du kobler til her, blir aldri skannet av seg selv.',
    'microsoft_card_body_phone' => 'Microsoft 365 og Outlook.com skannes av skrivebordsappen. En konto du kobler til her, blir aldri skannet av seg selv.',

    'discovered_heading' => 'Oppdagede avsendere',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (kontoutskrifter)',
    ],
    'discovered_body' => 'Avsendere som ser ut til å sende kvitteringer, men som ennå ikke står på listen din over kjente kvitteringsavsendere. Legg til dem du vil at Beatrax skal skanne, og lukk resten.',
    'last_seen' => 'sist sett',
    'seen_times' => 'Sett :count gang|Sett :count ganger',
    'add' => 'Legg til',
    'add_aria' => 'Legg til :email',
    'dismiss' => 'Lukk',
    'dismiss_aria' => 'Lukk :email',

    'toast' => [
        'reconnect_first' => 'Koble til denne innboksen på nytt før du skanner.',
        'scan_in_progress' => 'Skanning pågår allerede.',
        'scan_started' => 'Skanningen er startet.',
        'sender_added' => 'Avsenderen er lagt til.',
        'sender_dismissed' => 'Avsenderen er lukket.',
    ],
];
