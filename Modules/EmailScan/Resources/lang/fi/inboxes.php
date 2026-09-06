<?php

declare(strict_types=1);

return [
    'heading' => 'Postilaatikot',
    'intro' => 'Yhdistä Gmail- ja Microsoft 365 -postilaatikot, jotta Beatrax voi skannata niistä kuitteja.',
    'intro_phone' => 'Postilaatikoiden skannaus tapahtuu työpöytäsovelluksessa, ei tässä puhelimessa.',

    'phone_heading' => 'Tämä puhelin ei skannaa postilaatikoita',
    'phone_body' => 'Yhdistä Gmail tai Microsoft 365 työpöytäsovelluksessa — sen löytämät kuitit saapuvat tänne synkronoinnin kautta.',
    'connection_canceled' => 'Yhdistäminen peruutettu.',
    'connection_failed' => 'Yhteyttä ei saatu muodostettua.',

    'backfilling' => 'Haetaan takautuvasti',
    'backfill_progress' => ':fetched / ~:count viesti|:fetched / ~:count viestiä',

    'connect_heading' => 'Yhdistä sähköpostisi',
    'connect_body' => 'Tuo kuitteja PayPalista, ICS Cardsista, Google Playsta ja muilta kauppiailta antamalla Beatraxille vain luku -oikeuden yhteen tai useampaan postilaatikkoosi.',
    'connect_body_phone' => 'Kuitit PayPalista, ICS Cardsista, Google Playsta ja muilta kauppiailta tuo työpöytäsovellus niistä postilaatikoista, joihin annat sille vain luku -oikeuden. Tämä puhelin näyttää, mitä tuo tuonti löytää.',
    'connect_gmail' => 'Yhdistä Gmail',
    'connect_microsoft' => 'Yhdistä Microsoft 365',
    'readonly_note' => 'Beatrax vain lukee viestejä. Se ei koskaan lähetä, merkitse, siirrä tai poista mitään postilaatikossasi.',

    'months' => ':count kuukausi|:count kuukautta',
    'not_scanned_yet' => 'ei vielä skannattu',
    'not_scanned_yet_phone' => 'ei skannattu tässä puhelimessa',
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

    'error_detail' => 'Viimeisin skannaus ei valmistunut. Kokeile Skannaa nyt tai yhdistä tämä postilaatikko uudelleen.',
    'oauth_state_mismatch' => 'Tämä yhteyslinkki on vanhentunut tai jo käytetty. Aloita yhdistäminen alusta.',
    'oauth_client_missing' => 'Tämän sähköpostipalvelun kertaluonteista määritystä ei ole tehty loppuun tällä laitteella, joten yhdistämiseen ei ole vielä mitään. Paina uudelleen Yhdistä, niin saat sen valmiiksi.',
    'oauth_no_code' => 'Sähköpostipalvelusi palautti sinut ilman koodia, jota Beatrax tarvitsee viimeistelyyn, joten yhtään postilaatikkoa ei yhdistetty. Aloita yhdistäminen alusta.',
    'oauth_grant_refused' => 'Sähköpostipalvelusi hylkäsi Beatraxille annetun luvan — se on vanhentunut tai peruttu. Aloita yhdistäminen alusta ja myönnä lupa.',
    'oauth_exchange_failed' => 'Sähköpostipalvelusi ei saanut yhdistämistä valmiiksi, joten postilaatikkoa ei lisätty. Yritä uudelleen muutaman minuutin kuluttua.',
    'oauth_not_saved' => 'Yhteyttä ei voitu tallentaa tälle laitteelle, joten postilaatikkoa ei lisätty. Yritä uudelleen — jos se epäonnistuu toistuvasti, sovelluksen loki kertoo, mikä sen pysäytti.',
    'oauth_no_offline_access_google' => 'Google ei myöntänyt pysyvää lupaa, jota Beatrax tarvitsee, joten tämän postilaatikon skannaus loppuisi tunnin sisällä. Julkaise OAuth-suostumusnäyttösi tuotantoon ja yhdistä sitten uudelleen.',
    'oauth_no_offline_access' => 'Sähköpostipalvelusi ei myöntänyt pysyvää lupaa, jota Beatrax tarvitsee, joten tämän postilaatikon skannaus loppuisi tunnin sisällä. Yhdistä uudelleen ja salli offline-käyttö, kun sitä kysytään.',
    'oauth_no_offline_access_google_phone' => 'Google ei myöntänyt pysyvää lupaa, jota Beatrax tarvitsee, joten yhtään postilaatikkoa ei yhdistetty. Julkaise OAuth-suostumusnäyttösi tuotantoon ja yhdistä sitten uudelleen — itse skannaus tapahtuu työpöytäsovelluksessa.',
    'oauth_no_offline_access_phone' => 'Sähköpostipalvelusi ei myöntänyt pysyvää lupaa, jota Beatrax tarvitsee, joten yhtään postilaatikkoa ei yhdistetty. Yhdistä uudelleen ja salli offline-käyttö, kun sitä kysytään — itse skannaus tapahtuu työpöytäsovelluksessa.',

    'retry_seconds' => 'uusi yritys :ns kuluttua',
    'retry_minutes' => 'uusi yritys :nm kuluttua',
    'retry_hours' => 'uusi yritys :nh kuluttua',

    'reconnect' => 'Yhdistä uudelleen',
    'disconnect' => 'Katkaise yhteys',
    'disconnect_confirm' => 'Katkaistaanko yhteys osoitteeseen :email? Tämä poistaa tämän postilaatikon tallennetut tunnukset, sen skannaushistorian ja lähettäjät, jotka olet lisännyt tai ohittanut. Beatraxiin jo kirjatut kuitit säilyvät ennallaan. Uusi yhdistäminen aloittaa skannauksen alusta.',
    'scan_now' => 'Skannaa nyt',
    'scan_in_progress_title' => 'Skannaus on jo käynnissä',

    'add_another' => 'Lisää toinen postilaatikko',
    'gmail_card_body' => 'Yhdistä Gmail-tili, jotta Beatrax voi skannata siitä kuitteja.',
    'microsoft_card_body' => 'Yhdistä Microsoft 365- tai Outlook.com-tili, jotta Beatrax voi skannata siitä kuitteja.',
    'gmail_card_body_phone' => 'Gmailin skannaa työpöytäsovellus. Yhdistä se siellä — tämä puhelin näyttää, mitä se löytää.',
    'microsoft_card_body_phone' => 'Microsoft 365- ja Outlook.com-tilit skannaa työpöytäsovellus. Yhdistä ne siellä — tämä puhelin näyttää, mitä se löytää.',

    'discovered_heading' => 'Löydetyt lähettäjät',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (tiliotteet)',
    ],
    'discovered_body' => 'Lähettäjät, jotka näyttävät lähettävän kuitteja mutta joita ei vielä ole tunnettujen kuittilähettäjien listallasi. Lisää ne, jotka haluat Beatraxin skannaavan, ja ohita loput.',
    'last_seen' => 'viimeksi nähty',
    'seen_times' => 'Nähty :count kerran|Nähty :count kertaa',
    'add' => 'Lisää',
    'add_aria' => 'Lisää :email',
    'dismiss' => 'Ohita',
    'dismiss_aria' => 'Ohita :email',

    'toast' => [
        'reconnect_first' => 'Yhdistä tämä postilaatikko uudelleen ennen skannausta.',
        'scan_in_progress' => 'Skannaus on jo käynnissä.',
        'scan_started' => 'Skannaus aloitettu.',
        'sender_added' => 'Lähettäjä lisätty.',
        'sender_dismissed' => 'Lähettäjä ohitettu.',
    ],
];
