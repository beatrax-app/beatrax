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
    'camera_off_no_search' => 'Kaamera kasutamine on välja lülitatud ja teise seadme otsimine võrgust ei tööta iPhone’is veel — sisestatud koodil pole seega millegagi teda leida. Luba kaamera Beatraxile oma seadme seadetes ja skanni teise seadme kood.',
    'no_search' => 'Teise seadme otsimine võrgust ei tööta iPhone’is veel, seega pole sisestatud koodil midagi leida. Skanni kood selle asemel kaameraga — kaamera ei pea võrgust otsima.',
    'word_code_aria' => 'Sisesta teise seadme sõnakood',
    'submit_code' => 'Saada kood',
    'cancel' => 'Tühista',
    'skip_import' => 'Jätka importimata',

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
        'no_road_home' => 'See seade ei saa võrgust otsida ja skannitud kood ei sisalda teise seadme aadressi. Palu tal näidata uut koodi ja skanni see.',
        'invalid_code' => 'See kood on vigane või aegunud. Palu teisel seadmel uus luua.',
        'code_incomplete' => 'See kood ei ole täielik. Võrdle seda teise seadmega ja sisesta see tervikuna.',
        'code_not_accepted' => 'Ükski selle võrgu seade ei võtnud koodi vastu. Kontrolli koodi ja seda, kas teine seade näitab seda veel.',
        'no_peer_answered' => 'Selles võrgus ei vastanud sellele koodile miski. Kontrolli, kas teises seadmes töötab sünkroonimine, või skanni selle kood kaameraga — kaamera ei pea võrgust otsima.',
        'no_peer_answered_ios' => 'Selles võrgus ei vastanud sellele koodile miski. Teise seadme otsimine võrgust ei tööta iPhone’is veel, seega skanni selle kood kaameraga.',
        'no_peer_answered_camera_off' => 'Selles võrgus ei vastanud sellele koodile miski. Teise seadme otsimine võrgust ei tööta iPhone’is veel ja kaamera kasutamine on välja lülitatud — luba seetõttu kaamera Beatraxile oma seadme seadetes ja skanni teise seadme kood.',
        'rate_limited' => 'Liiga palju katseid. Oota minut ja proovi uuesti.',
        'identity_locked' => 'Sinu seadme identiteet on lukus. Ava rakendus ja proovi uuesti.',
        'identity_needs_lock' => 'Seadista esmalt rakenduse lukustus — see kaitseb seadme identiteeti.',
        'safety_number_changed' => 'Teine seade muutus võrdlemise ajal. Kontrolli allolevaid sõnu uuesti, enne kui kinnitad.',
    ],
];
