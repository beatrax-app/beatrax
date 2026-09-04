<?php

declare(strict_types=1);

return [
    'heading' => 'Nabiralniki',
    'intro' => 'Poveži nabiralnike Gmail in Microsoft 365, da jih Beatrax lahko pregleduje in išče potrdila.',
    'intro_phone' => 'Pregledovanje nabiralnikov teče v namizni aplikaciji, ne na tem telefonu.',

    'phone_heading' => 'Ta telefon ne pregleduje nabiralnikov',
    'phone_body' => 'Poveži Gmail ali Microsoft 365 v namizni aplikaciji — potrdila, ki jih tam najde, pridejo sem prek sinhronizacije.',
    'connection_canceled' => 'Povezovanje je preklicano.',
    'connection_failed' => 'Povezave ni bilo mogoče dokončati.',

    'backfilling' => 'Dopolnjevanje',
    'backfill_progress' => ':fetched / ~:count sporočilo|:fetched / ~:count sporočili|:fetched / ~:count sporočila|:fetched / ~:count sporočil',

    'connect_heading' => 'Poveži svojo e-pošto',
    'connect_body' => 'Uvozi potrdila s PayPala, ICS Cards, Google Play in drugih trgovcev tako, da Beatraxu daš dostop samo za branje do enega ali več svojih nabiralnikov.',
    'connect_body_phone' => 'Potrdila s PayPala, ICS Cards, Google Play in drugih trgovcev uvozi namizna aplikacija iz nabiralnikov, do katerih ji daš dostop samo za branje. Ta telefon prikazuje, kar ta uvoz najde.',
    'connect_gmail' => 'Poveži Gmail',
    'connect_microsoft' => 'Poveži Microsoft 365',
    'readonly_note' => 'Beatrax sporočila samo bere. V tvojem nabiralniku nikoli ničesar ne pošlje, označi, premakne ali izbriše.',

    'months' => ':count mes.|:count mes.|:count mes.|:count mes.',
    'not_scanned_yet' => 'še ni pregledano',
    'not_scanned_yet_phone' => 'na tem telefonu ni pregledano',
    'last_scanned' => 'zadnji pregled',
    'window_prefix' => 'Obdobje:',
    'edit' => 'Uredi',

    'badge' => [
        'idle' => 'Nedejavno',
        'backfilling' => 'Dopolnjevanje',
        'scanning' => 'Pregledovanje',
        'rate_limited' => 'Omejeno število zahtev',
        'needs_reauth' => 'Potrebna ponovna prijava',
        'error' => 'Napaka',
    ],

    'error_detail' => 'Zadnje pregledovanje se ni dokončalo. Poskusite Preglej zdaj ali znova povežite ta nabiralnik.',
    'oauth_state_mismatch' => 'Ta povezava za povezovanje je potekla ali je bila že uporabljena. Povezovanje začni znova.',
    'oauth_client_missing' => 'Enkratna nastavitev za tega ponudnika pošte v tej napravi ni dokončana, zato še ni ničesar, s čimer bi se povezal. Znova pritisni Poveži in jo dokončaj.',
    'oauth_no_code' => 'Tvoj ponudnik pošte te je vrnil brez kode, ki jo Beatrax potrebuje za dokončanje, zato ni bil povezan noben nabiralnik. Povezovanje začni znova.',
    'oauth_grant_refused' => 'Tvoj ponudnik pošte je zavrnil dovoljenje, dano Beatraxu — poteklo je ali je bilo umaknjeno. Povezovanje začni znova in ga odobri.',
    'oauth_exchange_failed' => 'Tvoj ponudnik pošte povezovanja ni dokončal, zato ni bil dodan noben nabiralnik. Poskusi znova čez nekaj minut.',
    'oauth_not_saved' => 'Povezave ni bilo mogoče shraniti v to napravo, zato ni bil dodan noben nabiralnik. Poskusi znova — če še naprej spodleti, dnevnik aplikacije zabeleži, kaj jo je ustavilo.',
    'oauth_no_offline_access_google' => 'Google ni podelil trajnega dovoljenja, ki ga Beatrax potrebuje, zato bi se ta nabiralnik nehal pregledovati v eni uri. Objavi svoj zaslon za privolitev OAuth v produkcijo in se poveži znova.',
    'oauth_no_offline_access' => 'Tvoj ponudnik pošte ni podelil trajnega dovoljenja, ki ga Beatrax potrebuje, zato bi se ta nabiralnik nehal pregledovati v eni uri. Poveži se znova in ob vprašanju dovoli dostop brez povezave.',
    'oauth_no_offline_access_google_phone' => 'Google ni podelil trajnega dovoljenja, ki ga Beatrax potrebuje, zato ni bil povezan noben nabiralnik. Objavi svoj zaslon za privolitev OAuth v produkcijo in se poveži znova — samo pregledovanje teče v namizni aplikaciji.',
    'oauth_no_offline_access_phone' => 'Tvoj ponudnik pošte ni podelil trajnega dovoljenja, ki ga Beatrax potrebuje, zato ni bil povezan noben nabiralnik. Poveži se znova in ob vprašanju dovoli dostop brez povezave — samo pregledovanje teče v namizni aplikaciji.',

    'retry_seconds' => 'ponovni poskus čez :ns',
    'retry_minutes' => 'ponovni poskus čez :nmin',
    'retry_hours' => 'ponovni poskus čez :nh',

    'reconnect' => 'Znova poveži',
    'disconnect' => 'Prekini povezavo',
    'scan_now' => 'Preglej zdaj',
    'scan_in_progress_title' => 'Pregledovanje že poteka',

    'add_another' => 'Dodaj še en nabiralnik',
    'gmail_card_body' => 'Poveži račun Gmail, da ga Beatrax lahko pregleduje in išče potrdila.',
    'microsoft_card_body' => 'Poveži račun Microsoft 365 ali Outlook.com, da ga Beatrax lahko pregleduje in išče potrdila.',
    'gmail_card_body_phone' => 'Gmail pregleduje namizna aplikacija. Račun, povezan tukaj, se nikoli ne pregleda sam od sebe.',
    'microsoft_card_body_phone' => 'Microsoft 365 in Outlook.com pregleduje namizna aplikacija. Račun, povezan tukaj, se nikoli ne pregleda sam od sebe.',

    'discovered_heading' => 'Odkriti pošiljatelji',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (izpiski)',
    ],
    'discovered_body' => 'Pošiljatelji, ki so videti, kot da pošiljajo potrdila, a jih še ni na tvojem seznamu znanih pošiljateljev potrdil. Dodaj tiste, ki naj jih Beatrax pregleduje, ostale opusti.',
    'last_seen' => 'nazadnje videno',
    'seen_times' => 'Število pojavitev: :count|Število pojavitev: :count|Število pojavitev: :count|Število pojavitev: :count',
    'add' => 'Dodaj',
    'add_aria' => 'Dodaj :email',
    'dismiss' => 'Opusti',
    'dismiss_aria' => 'Opusti :email',

    'toast' => [
        'reconnect_first' => 'Pred pregledovanjem znova poveži ta nabiralnik.',
        'scan_in_progress' => 'Pregledovanje že poteka.',
        'scan_started' => 'Pregledovanje se je začelo.',
        'sender_added' => 'Pošiljatelj je dodan.',
        'sender_dismissed' => 'Pošiljatelj je opuščen.',
    ],
];
