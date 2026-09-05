<?php

declare(strict_types=1);

return [
    'heading' => 'Ulazni pretinci',
    'intro' => 'Poveži ulazne pretince Gmaila i Microsofta 365 kako bi ih Beatrax mogao skenirati u potrazi za potvrdama.',
    'intro_phone' => 'Skeniranje pretinaca radi u aplikaciji za računalo, ne na ovom telefonu.',

    'phone_heading' => 'Ovaj telefon ne skenira sandučiće',
    'phone_body' => 'Poveži Gmail ili Microsoft 365 u aplikaciji za računalo — potvrde koje ondje nađe stižu ovamo sinkronizacijom.',
    'connection_canceled' => 'Povezivanje je otkazano.',
    'connection_failed' => 'Povezivanje nije bilo moguće dovršiti.',

    'backfilling' => 'Dopunjavanje',
    'backfill_progress' => ':fetched / ~:count poruka|:fetched / ~:count poruke|:fetched / ~:count poruka',

    'connect_heading' => 'Poveži svoju e-poštu',
    'connect_body' => 'Uvezi potvrde s PayPala, ICS Cardsa, Google Playa i drugih trgovaca tako da Beatraxu daš pristup samo za čitanje jednom ili više svojih ulaznih pretinaca.',
    'connect_body_phone' => 'Potvrde s PayPala, ICS Cardsa, Google Playa i drugih trgovaca uvozi aplikacija za računalo, iz pretinaca kojima joj daš pristup samo za čitanje. Ovaj telefon pokazuje što taj uvoz nađe.',
    'connect_gmail' => 'Poveži Gmail',
    'connect_microsoft' => 'Poveži Microsoft 365',
    'readonly_note' => 'Beatrax samo čita poruke. Nikad ništa ne šalje, ne označava, ne premješta niti ne briše u tvom ulaznom pretincu.',

    'months' => ':count mj.|:count mj.|:count mj.',
    'not_scanned_yet' => 'još nije skenirano',
    'not_scanned_yet_phone' => 'nije skenirano na ovom telefonu',
    'last_scanned' => 'zadnje skeniranje',
    'window_prefix' => 'Razdoblje:',
    'edit' => 'Uredi',

    'badge' => [
        'idle' => 'Mirovanje',
        'backfilling' => 'Dopunjavanje',
        'scanning' => 'Skeniranje',
        'rate_limited' => 'Ograničen broj zahtjeva',
        'needs_reauth' => 'Potrebna ponovna prijava',
        'error' => 'Pogreška',
    ],

    'error_detail' => 'Posljednje skeniranje nije dovršeno. Pokušajte Skeniraj sada ili ponovno povežite ovaj sandučić.',
    'oauth_state_mismatch' => 'Ova poveznica za povezivanje istekla je ili je već iskorištena. Započni povezivanje ispočetka.',
    'oauth_client_missing' => 'Jednokratna postava za tog davatelja pošte nije dovršena na ovom uređaju, pa još nema čime uspostaviti vezu. Pritisni ponovno Poveži da je dovršiš.',
    'oauth_no_code' => 'Tvoj davatelj pošte vratio te bez koda koji Beatraxu treba za dovršetak, pa nijedan sandučić nije povezan. Započni povezivanje ispočetka.',
    'oauth_grant_refused' => 'Tvoj davatelj pošte odbio je dopuštenje dano Beatraxu — isteklo je ili je povučeno. Započni povezivanje ispočetka i odobri ga.',
    'oauth_exchange_failed' => 'Tvoj davatelj pošte nije dovršio povezivanje, pa nijedan sandučić nije dodan. Pokušaj ponovno za nekoliko minuta.',
    'oauth_not_saved' => 'Vezu nije bilo moguće spremiti na ovaj uređaj, pa nijedan sandučić nije dodan. Pokušaj ponovno — ako i dalje ne uspijeva, zapisnik aplikacije bilježi što ju je zaustavilo.',
    'oauth_no_offline_access_google' => 'Google nije dao trajno dopuštenje koje Beatraxu treba, pa bi ovaj sandučić prestao biti pregledavan unutar sat vremena. Objavi svoj OAuth zaslon privole u produkciju pa se poveži ponovno.',
    'oauth_no_offline_access' => 'Tvoj davatelj pošte nije dao trajno dopuštenje koje Beatraxu treba, pa bi ovaj sandučić prestao biti pregledavan unutar sat vremena. Poveži se ponovno i dopusti izvanmrežni pristup kad te pita.',
    'oauth_no_offline_access_google_phone' => 'Google nije dao trajno dopuštenje koje Beatraxu treba, pa nijedan sandučić nije povezan. Objavi svoj OAuth zaslon privole u produkciju pa se poveži ponovno — samo skeniranje radi u aplikaciji za računalo.',
    'oauth_no_offline_access_phone' => 'Tvoj davatelj pošte nije dao trajno dopuštenje koje Beatraxu treba, pa nijedan sandučić nije povezan. Poveži se ponovno i dopusti izvanmrežni pristup kad te pita — samo skeniranje radi u aplikaciji za računalo.',

    'retry_seconds' => 'ponovni pokušaj za :ns',
    'retry_minutes' => 'ponovni pokušaj za :nmin',
    'retry_hours' => 'ponovni pokušaj za :nh',

    'reconnect' => 'Poveži ponovno',
    'disconnect' => 'Odspoji',
    'disconnect_confirm' => 'Odspojiti :email? Ovo uklanja pohranjene vjerodajnice ovog pretinca, njegovu povijest skeniranja i pošiljatelje koje si dodao ili odbacio. Potvrde koje su već zavedene u Beatrax ostaju netaknute. Ponovno povezivanje kreće s novim skeniranjem.',
    'scan_now' => 'Skeniraj sada',
    'scan_in_progress_title' => 'Skeniranje je već u tijeku',

    'add_another' => 'Dodaj još jedan ulazni pretinac',
    'gmail_card_body' => 'Poveži Gmail račun kako bi ga Beatrax mogao skenirati u potrazi za potvrdama.',
    'microsoft_card_body' => 'Poveži Microsoft 365 ili Outlook.com račun kako bi ga Beatrax mogao skenirati u potrazi za potvrdama.',
    'gmail_card_body_phone' => 'Gmail skenira aplikacija za računalo. Račun povezan ovdje nikad se ne skenira sam od sebe.',
    'microsoft_card_body_phone' => 'Microsoft 365 i Outlook.com skenira aplikacija za računalo. Račun povezan ovdje nikad se ne skenira sam od sebe.',

    'discovered_heading' => 'Otkriveni pošiljatelji',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (izvodi)',
    ],
    'discovered_body' => 'Pošiljatelji koji izgledaju kao da šalju potvrde, ali još nisu na tvom popisu poznatih pošiljatelja potvrda. Dodaj one koje želiš da Beatrax skenira, ostale odbaci.',
    'last_seen' => 'zadnji put viđeno',
    'seen_times' => 'Broj pojavljivanja: :count|Broj pojavljivanja: :count|Broj pojavljivanja: :count',
    'add' => 'Dodaj',
    'add_aria' => 'Dodaj :email',
    'dismiss' => 'Odbaci',
    'dismiss_aria' => 'Odbaci :email',

    'toast' => [
        'reconnect_first' => 'Ponovno poveži ovaj pretinac prije skeniranja.',
        'scan_in_progress' => 'Skeniranje je već u tijeku.',
        'scan_started' => 'Skeniranje je pokrenuto.',
        'sender_added' => 'Pošiljatelj je dodan.',
        'sender_dismissed' => 'Pošiljatelj je odbačen.',
    ],
];
