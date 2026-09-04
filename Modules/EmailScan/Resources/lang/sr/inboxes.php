<?php

declare(strict_types=1);

return [
    'heading' => 'Prijemna sandučad',
    'intro' => 'Poveži prijemna sandučad Gmaila i Microsofta 365 da bi Beatrax mogao da ih skenira u potrazi za potvrdama.',
    'intro_phone' => 'Skeniranje sandučadi radi u aplikaciji za računar, ne na ovom telefonu.',

    'phone_heading' => 'Ovaj telefon ne skenira sandučad',
    'phone_body' => 'Poveži Gmail ili Microsoft 365 u aplikaciji za računar — potvrde koje tamo nađe stižu ovamo sinhronizacijom.',
    'connection_canceled' => 'Povezivanje je otkazano.',
    'connection_failed' => 'Povezivanje nije moglo da se dovrši.',

    'backfilling' => 'Dopunjavanje',
    'backfill_progress' => ':fetched / ~:count poruka|:fetched / ~:count poruke|:fetched / ~:count poruka',

    'connect_heading' => 'Poveži svoju e-poštu',
    'connect_body' => 'Uvezi potvrde sa PayPala, ICS Cardsa, Google Playa i drugih trgovaca tako što ćeš Beatraxu dati pristup samo za čitanje jednom ili više svojih prijemnih sandučadi.',
    'connect_body_phone' => 'Potvrde sa PayPala, ICS Cardsa, Google Playa i drugih trgovaca uvozi aplikacija za računar, iz sandučadi kojima joj daš pristup samo za čitanje. Ovaj telefon pokazuje šta taj uvoz nađe.',
    'connect_gmail' => 'Poveži Gmail',
    'connect_microsoft' => 'Poveži Microsoft 365',
    'readonly_note' => 'Beatrax samo čita poruke. Nikad ništa ne šalje, ne označava, ne premešta niti ne briše u tvom prijemnom sandučetu.',

    'months' => ':count mes.|:count mes.|:count mes.',
    'not_scanned_yet' => 'još nije skenirano',
    'not_scanned_yet_phone' => 'nije skenirano na ovom telefonu',
    'last_scanned' => 'poslednje skeniranje',
    'window_prefix' => 'Period:',
    'edit' => 'Izmeni',

    'badge' => [
        'idle' => 'Mirovanje',
        'backfilling' => 'Dopunjavanje',
        'scanning' => 'Skeniranje',
        'rate_limited' => 'Ograničen broj zahteva',
        'needs_reauth' => 'Potrebna ponovna prijava',
        'error' => 'Greška',
    ],

    'error_detail' => 'Poslednje skeniranje nije dovršeno. Pokušajte Skeniraj sada ili ponovo povežite ovo sanduče.',
    'oauth_state_mismatch' => 'Ova veza za povezivanje je istekla ili je već iskorišćena. Započni povezivanje iznova.',
    'oauth_no_code' => 'Tvoj provajder pošte vratio te bez koda koji je Beatraxu potreban da završi, pa nijedno sanduče nije povezano. Započni povezivanje iznova.',
    'oauth_grant_refused' => 'Tvoj provajder pošte odbio je dozvolu datu Beatraxu — istekla je ili je povučena. Započni povezivanje iznova i odobri je.',
    'oauth_exchange_failed' => 'Tvoj provajder pošte nije završio povezivanje, pa nijedno sanduče nije dodato. Pokušaj ponovo za nekoliko minuta.',
    'oauth_not_saved' => 'Vezu nije bilo moguće sačuvati na ovom uređaju, pa nijedno sanduče nije dodato. Pokušaj ponovo — ako i dalje ne uspeva, dnevnik aplikacije beleži šta ju je zaustavilo.',
    'oauth_no_offline_access_google' => 'Google nije dao trajnu dozvolu koja je Beatraxu potrebna, pa bi ovo sanduče prestalo da se skenira u roku od sat vremena. Objavi svoj OAuth ekran saglasnosti u produkciju pa se poveži ponovo.',
    'oauth_no_offline_access' => 'Tvoj provajder pošte nije dao trajnu dozvolu koja je Beatraxu potrebna, pa bi ovo sanduče prestalo da se skenira u roku od sat vremena. Poveži se ponovo i dozvoli oflajn pristup kad te pita.',
    'oauth_no_offline_access_google_phone' => 'Google nije dao trajnu dozvolu koja je Beatraxu potrebna, pa nijedno sanduče nije povezano. Objavi svoj OAuth ekran saglasnosti u produkciju pa se poveži ponovo — samo skeniranje radi u aplikaciji za računar.',
    'oauth_no_offline_access_phone' => 'Tvoj provajder pošte nije dao trajnu dozvolu koja je Beatraxu potrebna, pa nijedno sanduče nije povezano. Poveži se ponovo i dozvoli oflajn pristup kad te pita — samo skeniranje radi u aplikaciji za računar.',

    'retry_seconds' => 'ponovni pokušaj za :ns',
    'retry_minutes' => 'ponovni pokušaj za :nmin',
    'retry_hours' => 'ponovni pokušaj za :nh',

    'reconnect' => 'Poveži ponovo',
    'disconnect' => 'Prekini vezu',
    'scan_now' => 'Skeniraj sada',
    'scan_in_progress_title' => 'Skeniranje je već u toku',

    'add_another' => 'Dodaj još jedno prijemno sanduče',
    'gmail_card_body' => 'Poveži Gmail nalog da bi Beatrax mogao da ga skenira u potrazi za potvrdama.',
    'microsoft_card_body' => 'Poveži Microsoft 365 ili Outlook.com nalog da bi Beatrax mogao da ga skenira u potrazi za potvrdama.',
    'gmail_card_body_phone' => 'Gmail skenira aplikacija za računar. Nalog povezan ovde nikada se ne skenira sam od sebe.',
    'microsoft_card_body_phone' => 'Microsoft 365 i Outlook.com skenira aplikacija za računar. Nalog povezan ovde nikada se ne skenira sam od sebe.',

    'discovered_heading' => 'Otkriveni pošiljaoci',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (izvodi)',
    ],
    'discovered_body' => 'Pošiljaoci koji izgledaju kao da šalju potvrde, ali još nisu na tvojoj listi poznatih pošiljalaca potvrda. Dodaj one koje želiš da Beatrax skenira, ostale odbaci.',
    'last_seen' => 'poslednji put viđeno',
    'seen_times' => 'Broj pojavljivanja: :count|Broj pojavljivanja: :count|Broj pojavljivanja: :count',
    'add' => 'Dodaj',
    'add_aria' => 'Dodaj :email',
    'dismiss' => 'Odbaci',
    'dismiss_aria' => 'Odbaci :email',

    'toast' => [
        'reconnect_first' => 'Ponovo povežite ovo sanduče pre skeniranja.',
        'scan_in_progress' => 'Skeniranje je već u toku.',
        'scan_started' => 'Skeniranje je pokrenuto.',
        'sender_added' => 'Pošiljalac je dodat.',
        'sender_dismissed' => 'Pošiljalac je odbačen.',
    ],
];
