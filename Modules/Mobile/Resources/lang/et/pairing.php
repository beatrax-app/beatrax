<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Seotud seade',
    'page_title' => 'Seo seade',

    'scan_heading' => 'Seo see seade',
    'scan_subtitle' => 'Suuna kaamera teises seadmes kuvatavale koodile.',
    'camera_permission_pending' => 'Kaamera kasutamine on välja lülitatud. Luba see oma seadme seadetes Beatraxile ja proovi uuesti.',
    'open_camera' => 'Ava kaamera',
    'opening_camera' => 'Ootan kaamera luba…',
    'close_camera' => 'Sulge kaamera',
    'viewfinder_aria' => 'Kaamera vaateaken — suuna see teises seadmes olevale koodile',
    'viewfinder_idle' => 'Kaamera on välja lülitatud. Ava see, et skannida teises seadmes kuvatavat koodi.',
    'scan_prompt' => 'Skanni kood oma teisest seadmest',
    'enter_code_instead' => 'Sisesta kood käsitsi',

    'enter_heading' => 'Sisesta kood',
    'camera_off' => 'Kaamera kasutamine on välja lülitatud. Sisesta selle asemel teise seadme kood.',
    'word_code_aria' => 'Sisesta teise seadme sõnakood',
    'submit_code' => 'Saada kood',
    'cancel' => 'Tühista',

    'confirm_heading' => 'Võrdle neid sõnu teise seadmega',
    'safety_words_aria' => 'Turvanumbri sõnad: :words',
    'confirm_body' => 'Mõlemas seadmes peavad olema täpselt samad sõnad. Kui need erinevad, puuduta Tühista — käimas võib olla vahendusrünne.',
    'awaiting_peer' => 'Ootan teise seadme kinnitust...',
    'confirm_match' => 'Kinnita — need kattuvad',

    'success_heading' => 'Seade on seotud',
    'success_body' => 'See seade on nüüd usaldusväärne. Sinu andmed sünkroonitakse, kui ühenduse lood.',
    'done' => 'Valmis',

    'errors' => [
        'relay_unreachable' => 'Teise seadmeni ei saa. Veendu, et mõlemad on samas võrgus ja et töölauas on sünkroonimine sisse lülitatud.',
        'invalid_code' => 'See kood on vigane või aegunud. Palu teisel seadmel uus luua.',
        'code_not_accepted' => 'Ükski selle võrgu seade ei võtnud koodi vastu. Kontrolli koodi ja seda, kas teine seade näitab seda veel.',
        'rate_limited' => 'Liiga palju katseid. Oota minut ja proovi uuesti.',
        'identity_locked' => 'Sinu seadme identiteet on lukus. Ava rakendus ja proovi uuesti.',
        'identity_needs_lock' => 'Seadista esmalt rakenduse lukustus — see kaitseb seadme identiteeti.',
        'safety_number_changed' => 'Teine seade muutus võrdlemise ajal. Kontrolli allolevaid sõnu uuesti, enne kui kinnitad.',
    ],
];
