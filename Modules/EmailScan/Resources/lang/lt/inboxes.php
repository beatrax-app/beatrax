<?php

declare(strict_types=1);

return [
    'heading' => 'Pašto dėžutės',
    'intro' => 'Prijunk Gmail ir Microsoft 365 pašto dėžutes, kad Beatrax galėtų jose ieškoti kvitų.',
    'intro_phone' => 'Pašto dėžučių skenavimas vyksta kompiuterio programoje, ne šiame telefone.',

    'phone_heading' => 'Šis telefonas dėžučių neskenuoja',
    'phone_body' => 'Prijunk Gmail arba Microsoft 365 kompiuterio programoje — ten rasti kvitai atkeliauja čia per sinchronizavimą.',
    'connection_canceled' => 'Prijungimas atšauktas.',
    'connection_failed' => 'Prijungimo užbaigti nepavyko.',

    'backfilling' => 'Importuojami ankstesni duomenys',
    'backfill_progress' => ':fetched / ~:count laiškas|:fetched / ~:count laiškai|:fetched / ~:count laiškų',

    'connect_heading' => 'Prijunk savo el. paštą',
    'connect_body' => 'Importuok kvitus iš PayPal, ICS Cards, Google Play ir kitų prekybininkų suteikdamas Beatrax prieigą tik skaitymui prie vienos ar kelių savo pašto dėžučių.',
    'connect_body_phone' => 'Kvitus iš PayPal, ICS Cards, Google Play ir kitų prekybininkų importuoja kompiuterio programa iš tų dėžučių, kurioms suteiki jai prieigą tik skaitymui. Šis telefonas rodo, ką tas importas randa.',
    'connect_gmail' => 'Prijungti Gmail',
    'connect_microsoft' => 'Prijungti Microsoft 365',
    'readonly_note' => 'Beatrax laiškus tik skaito. Ji niekada nieko nesiunčia, nežymi, neperkelia ir netrina tavo pašto dėžutėje.',

    'months' => ':count mėnuo|:count mėnesiai|:count mėnesių',
    'not_scanned_yet' => 'dar nenuskaityta',
    'not_scanned_yet_phone' => 'šiame telefone nenuskaityta',
    'last_scanned' => 'paskutinį kartą nuskaityta',
    'window_prefix' => 'Laikotarpis:',
    'edit' => 'Redaguoti',

    'badge' => [
        'idle' => 'Neaktyvi',
        'backfilling' => 'Importuojama',
        'scanning' => 'Nuskaitoma',
        'rate_limited' => 'Ribojamas dažnis',
        'needs_reauth' => 'Reikia autentifikuotis iš naujo',
        'error' => 'Klaida',
    ],

    'error_detail' => 'Paskutinis nuskaitymas nebaigtas. Pabandykite „Nuskaityti dabar“ arba prijunkite šią dėžutę iš naujo.',
    'oauth_state_mismatch' => 'Ši prisijungimo nuoroda nebegalioja arba jau panaudota. Pradėk jungimą iš naujo.',
    'oauth_no_code' => 'Tavo pašto tiekėjas grąžino tave be kodo, kurio Beatrax reikia užbaigti, todėl nebuvo prijungta jokia dėžutė. Pradėk jungimą iš naujo.',
    'oauth_grant_refused' => 'Tavo pašto tiekėjas atmetė Beatrax suteiktą leidimą — jis baigė galioti arba buvo atšauktas. Pradėk jungimą iš naujo ir suteik jį.',
    'oauth_exchange_failed' => 'Tavo pašto tiekėjas neužbaigė jungimo, todėl nebuvo pridėta jokia dėžutė. Bandyk dar kartą po kelių minučių.',
    'oauth_not_saved' => 'Ryšio nepavyko išsaugoti šiame įrenginyje, todėl nebuvo pridėta jokia dėžutė. Bandyk dar kartą — jei vis nepavyksta, programos žurnale užrašyta, kas jį sustabdė.',
    'oauth_no_offline_access_google' => 'Google nesuteikė ilgalaikio leidimo, kurio reikia Beatrax, todėl ši dėžutė per valandą nustotų būti skenuojama. Paskelbk savo OAuth sutikimo ekraną gamybai ir prijunk iš naujo.',
    'oauth_no_offline_access' => 'Tavo pašto tiekėjas nesuteikė ilgalaikio leidimo, kurio reikia Beatrax, todėl ši dėžutė per valandą nustotų būti skenuojama. Prijunk iš naujo ir paklaustas leisk prieigą neprisijungus.',
    'oauth_no_offline_access_google_phone' => 'Google nesuteikė ilgalaikio leidimo, kurio reikia Beatrax, todėl nebuvo prijungta jokia dėžutė. Paskelbk savo OAuth sutikimo ekraną gamybai ir prijunk iš naujo — pats skenavimas vyksta kompiuterio programoje.',
    'oauth_no_offline_access_phone' => 'Tavo pašto tiekėjas nesuteikė ilgalaikio leidimo, kurio reikia Beatrax, todėl nebuvo prijungta jokia dėžutė. Prijunk iš naujo ir paklaustas leisk prieigą neprisijungus — pats skenavimas vyksta kompiuterio programoje.',

    'retry_seconds' => 'bandoma vėl po :ns',
    'retry_minutes' => 'bandoma vėl po :nm',
    'retry_hours' => 'bandoma vėl po :nh',

    'reconnect' => 'Prijungti iš naujo',
    'disconnect' => 'Atjungti',
    'scan_now' => 'Nuskaityti dabar',
    'scan_in_progress_title' => 'Nuskaitymas jau vyksta',

    'add_another' => 'Pridėti kitą pašto dėžutę',
    'gmail_card_body' => 'Prijunk Gmail paskyrą, kad Beatrax galėtų joje ieškoti kvitų.',
    'microsoft_card_body' => 'Prijunk Microsoft 365 arba Outlook.com paskyrą, kad Beatrax galėtų joje ieškoti kvitų.',
    'gmail_card_body_phone' => 'Gmail skenuoja kompiuterio programa. Čia prijungta paskyra niekada nėra skenuojama savaime.',
    'microsoft_card_body_phone' => 'Microsoft 365 ir Outlook.com skenuoja kompiuterio programa. Čia prijungta paskyra niekada nėra skenuojama savaime.',

    'discovered_heading' => 'Aptikti siuntėjai',

    'known_sender' => [
        'ics_statements' => 'ICS Cards (išrašai)',
    ],
    'discovered_body' => 'Siuntėjai, kurie panašūs į kvitų siuntėjus, bet dar nėra tavo žinomų kvitų sąraše. Pridėk tuos, kuriuos nori, kad Beatrax nuskaitytų; likusius paslėpk.',
    'last_seen' => 'paskutinį kartą matyta',
    'seen_times' => 'Matyta :count kartą|Matyta :count kartus|Matyta :count kartų',
    'add' => 'Pridėti',
    'add_aria' => 'Pridėti :email',
    'dismiss' => 'Slėpti',
    'dismiss_aria' => 'Slėpti :email',

    'toast' => [
        'reconnect_first' => 'Prieš nuskaitydami iš naujo prijunkite šią pašto dėžutę.',
        'scan_in_progress' => 'Nuskaitymas jau vyksta.',
        'scan_started' => 'Nuskaitymas pradėtas.',
        'sender_added' => 'Siuntėjas pridėtas.',
        'sender_dismissed' => 'Siuntėjas paslėptas.',
    ],
];
