<?php

declare(strict_types=1);

return [
    'heading' => 'Postilaatikot',
    'intro' => 'Yhdistä Gmail- ja Microsoft 365 -postilaatikot, jotta Beatrax voi skannata niistä kuitteja.',

    'connection_canceled' => 'Yhdistäminen peruutettu.',
    'connection_failed' => 'Yhteyttä ei saatu muodostettua.',

    'backfilling' => 'Haetaan takautuvasti',
    'messages_suffix' => 'viestiä',

    'connect_heading' => 'Yhdistä sähköpostisi',
    'connect_body' => 'Tuo kuitteja PayPalista, ICS Cardsista, Google Playsta ja muilta kauppiailta antamalla Beatraxille vain luku -oikeuden yhteen tai useampaan postilaatikkoosi.',
    'connect_gmail' => 'Yhdistä Gmail',
    'connect_microsoft' => 'Yhdistä Microsoft 365',
    'readonly_note' => 'Beatrax vain lukee viestejä. Se ei koskaan lähetä, merkitse, siirrä tai poista mitään postilaatikossasi.',

    'month' => '1 kuukausi',
    'months' => ':count kuukautta',
    'not_scanned_yet' => 'ei vielä skannattu',
    'last_scanned' => 'viimeksi skannattu',
    'window_prefix' => 'Ikkuna:',
    'edit' => 'Muokkaa',

    'badge' => [
        'idle' => 'Odottaa',
        'backfilling' => 'Haetaan takautuvasti',
        'scanning' => 'Skannataan',
        'rate_limited' => 'Nopeusrajoitettu',
        'needs_reauth' => 'Vaatii uuden tunnistautumisen',
        'error' => 'Virhe',
    ],

    'retry_seconds' => 'uusi yritys :ns kuluttua',
    'retry_minutes' => 'uusi yritys :nm kuluttua',
    'retry_hours' => 'uusi yritys :nh kuluttua',

    'reconnect' => 'Yhdistä uudelleen',
    'scan_now' => 'Skannaa nyt',
    'scan_in_progress_title' => 'Skannaus on jo käynnissä',

    'add_another' => 'Lisää toinen postilaatikko',
    'gmail_card_body' => 'Yhdistä Gmail-tili, jotta Beatrax voi skannata siitä kuitteja.',
    'microsoft_card_body' => 'Yhdistä Microsoft 365- tai Outlook.com-tili, jotta Beatrax voi skannata siitä kuitteja.',

    'discovered_heading' => 'Löydetyt lähettäjät',
    'discovered_body' => 'Lähettäjät, jotka näyttävät lähettävän kuitteja mutta joita ei vielä ole tunnettujen kuittilähettäjien listallasi. Lisää ne, jotka haluat Beatraxin skannaavan, ja ohita loput.',
    'last_seen' => 'viimeksi nähty',
    'seen_times' => 'Nähty :count kertaa',
    'add' => 'Lisää',
    'add_aria' => 'Lisää :email',
    'dismiss' => 'Ohita',
    'dismiss_aria' => 'Ohita :email',

    'toast' => [
        'scan_in_progress' => 'Skannaus on jo käynnissä.',
        'scan_started' => 'Skannaus aloitettu.',
        'sender_added' => 'Lähettäjä lisätty.',
        'sender_dismissed' => 'Lähettäjä ohitettu.',
    ],
];
