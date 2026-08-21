<?php

declare(strict_types=1);

return [
    'heading' => 'Ulazni pretinci',
    'intro' => 'Poveži ulazne pretince Gmaila i Microsofta 365 kako bi ih Beatrax mogao skenirati u potrazi za potvrdama.',

    'connection_canceled' => 'Povezivanje je otkazano.',
    'connection_failed' => 'Povezivanje nije bilo moguće dovršiti.',

    'backfilling' => 'Dopunjavanje',
    'messages_suffix' => 'poruka',

    'connect_heading' => 'Poveži svoju e-poštu',
    'connect_body' => 'Uvezi potvrde s PayPala, ICS Cardsa, Google Playa i drugih trgovaca tako da Beatraxu daš pristup samo za čitanje jednom ili više svojih ulaznih pretinaca.',
    'connect_gmail' => 'Poveži Gmail',
    'connect_microsoft' => 'Poveži Microsoft 365',
    'readonly_note' => 'Beatrax samo čita poruke. Nikad ništa ne šalje, ne označava, ne premješta niti ne briše u tvom ulaznom pretincu.',

    'months' => ':count mj.|:count mj.|:count mj.',
    'not_scanned_yet' => 'još nije skenirano',
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

    'retry_seconds' => 'ponovni pokušaj za :ns',
    'retry_minutes' => 'ponovni pokušaj za :nmin',
    'retry_hours' => 'ponovni pokušaj za :nh',

    'reconnect' => 'Poveži ponovno',
    'disconnect' => 'Odspoji',
    'scan_now' => 'Skeniraj sada',
    'scan_in_progress_title' => 'Skeniranje je već u tijeku',

    'add_another' => 'Dodaj još jedan ulazni pretinac',
    'gmail_card_body' => 'Poveži Gmail račun kako bi ga Beatrax mogao skenirati u potrazi za potvrdama.',
    'microsoft_card_body' => 'Poveži Microsoft 365 ili Outlook.com račun kako bi ga Beatrax mogao skenirati u potrazi za potvrdama.',

    'discovered_heading' => 'Otkriveni pošiljatelji',
    'discovered_body' => 'Pošiljatelji koji izgledaju kao da šalju potvrde, ali još nisu na tvom popisu poznatih pošiljatelja potvrda. Dodaj one koje želiš da Beatrax skenira, ostale odbaci.',
    'last_seen' => 'zadnji put viđeno',
    'seen_times' => 'Broj pojavljivanja: :count|Broj pojavljivanja: :count|Broj pojavljivanja: :count',
    'add' => 'Dodaj',
    'add_aria' => 'Dodaj :email',
    'dismiss' => 'Odbaci',
    'dismiss_aria' => 'Odbaci :email',

    'toast' => [
        'scan_in_progress' => 'Skeniranje je već u tijeku.',
        'scan_started' => 'Skeniranje je pokrenuto.',
        'sender_added' => 'Pošiljatelj je dodan.',
        'sender_dismissed' => 'Pošiljatelj je odbačen.',
    ],
];
