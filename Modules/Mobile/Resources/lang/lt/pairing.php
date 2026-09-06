<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Susietas įrenginys',
    'page_title' => 'Susieti įrenginį',

    'scan_heading' => 'Susieti šį įrenginį',
    'scan_subtitle' => 'Nukreipk kamerą į kitame įrenginyje rodomą kodą.',
    'camera_permission_pending' => 'Kameros prieiga išjungta. Įrenginio nustatymuose leisk ją Beatrax ir bandyk dar kartą.',
    'open_camera' => 'Atverti kamerą',
    'opening_camera' => 'Laukiama kameros prieigos…',
    'close_camera' => 'Uždaryti kamerą',
    'viewfinder_aria' => 'Kameros vaizdo ieškiklis — nukreipk jį į kodą kitame įrenginyje',
    'viewfinder_idle' => 'Kamera išjungta. Atverk ją, kad nuskaitytum kitame įrenginyje rodomą kodą.',
    'scan_prompt' => 'Nuskaityk kodą kitame įrenginyje',
    'enter_code_instead' => 'Įvesti kodą ranka',

    'enter_heading' => 'Įvesk kodą',
    'camera_off' => 'Kameros prieiga išjungta. Vietoj to įvesk kodą iš kito įrenginio.',
    'camera_off_no_search' => 'Kameros prieiga išjungta, o kito įrenginio paieška tinkle „iPhone“ dar neveikia — todėl įvestas kodas pats jo neras. Įrenginio nustatymuose vėl įjunk Beatrax kameros prieigą ir nuskaityk kitame įrenginyje rodomą kodą arba išsiųsk kodą čia ir šis ekranas paklaus, kur jis yra.',
    'no_search' => 'Kito įrenginio paieška tinkle „iPhone“ dar neveikia, todėl įvestas kodas pats jo neras. Nuskaityk kodą kamera — jai tinklo paieškos nereikia. Jei nuskaityti negali, išsiųsk kodą ir šis ekranas paklaus, kur yra kitas įrenginys.',
    'word_code_aria' => 'Įvesk žodinį kodą iš kito įrenginio',
    'initiator_address' => 'Kur yra kitas įrenginys?',
    'initiator_address_help' => 'Jo adresas šiame tinkle, kaip hostas ir prievadas. Kompiuteris jį rodo skiltyje Įrenginiai ir sinchronizavimas. Išsiųsk kodą dar kartą, kai jį įvesi.',
    'submit_code' => 'Pateikti kodą',
    'cancel' => 'Atšaukti',
    'skip_import' => 'Tęsti neimportuojant',

    'confirm_heading' => 'Palygink šiuos žodžius su kitu įrenginiu',
    'safety_words_aria' => 'Saugos numerio žodžiai: :words',
    'confirm_body' => 'Abu įrenginiai turi rodyti tiksliai tuos pačius žodžius. Jei jie skiriasi, spustelėk Atšaukti — galimas tarpininko (man-in-the-middle) atakos atvejis.',
    'awaiting_peer' => 'Laukiama, kol patvirtins kitas įrenginys…',
    'confirm_match' => 'Patvirtinti — sutampa',

    'success_heading' => 'Įrenginys susietas',
    'success_body' => 'Šiuo įrenginiu dabar pasitikima. Prisijungus duomenys bus sinchronizuoti.',
    'encryption_incomplete' => 'Įrenginys susietas, tačiau jame saugomų duomenų šifravimas nebuvo užbaigtas. Duomenys dar nesaugomi užšifruoti.',
    'done' => 'Atlikta',

    'errors' => [
        'relay_unreachable' => 'Nepavyksta pasiekti kito įrenginio. Įsitikink, kad abu yra tame pačiame tinkle ir kad kompiuteryje įjungtas sinchronizavimas.',
        'no_road_home' => 'Šis įrenginys negali ieškoti tinkle, o nuskaitytame kode nėra kito įrenginio adreso. Paprašyk jo parodyti naują kodą ir nuskaityk jį.',
        'invalid_code' => 'Šis kodas neteisingas arba nebegalioja. Paprašyk kitame įrenginyje sugeneruoti naują.',
        'already_under_way' => 'Šis įrenginys tą kodą jau priėmė ir laukia, kol patvirtins kitas įrenginys. Jei taip neatsitiks, paprašyk naujo kodo ir naudok jį.',
        'vouched_but_refused' => 'Kitas įrenginys tą kodą vis dar turi, bet šis įrenginys negalėjo jo priimti. Paprašyk jo naujo kodo ir naudok jį.',
        'code_incomplete' => 'Šis kodas nepilnas. Palygink jį su kitu įrenginiu ir įvesk visą.',
        'initiator_address_invalid' => 'Tai nėra adresas, kuriuo šis įrenginys galėtų skambinti. Įvesk jį kaip hostą ir prievadą, pavyzdžiui 192.168.1.20:8100.',
        'code_not_accepted' => 'Nė vienas šio tinklo įrenginys nepriėmė šio kodo. Patikrink kodą ir ar kitas įrenginys jį vis dar rodo.',
        'no_peer_answered' => 'Šiame tinkle į šį kodą niekas neatsakė. Patikrink, ar kitame įrenginyje veikia sinchronizavimas, arba nuskaityk jo kodą kamera — kamerai tinkle ieškoti nereikia.',
        'no_peer_answered_ios' => 'Šiame tinkle į šį kodą niekas neatsakė. Kito įrenginio paieška tinkle „iPhone“ dar neveikia, tad nuskaityk jo kodą kamera.',
        'no_peer_answered_camera_off' => 'Šiame tinkle į šį kodą niekas neatsakė. Kito įrenginio paieška tinkle „iPhone“ dar neveikia, o kameros prieiga išjungta — tad įrenginio nustatymuose vėl leisk kamerą Beatrax ir nuskaityk kito įrenginio kodą.',
        'rate_limited' => 'Per daug bandymų. Palauk minutę ir bandyk dar kartą.',
        'identity_locked' => 'Tavo įrenginio tapatybė užrakinta. Atrakink programėlę ir bandyk dar kartą.',
        'identity_needs_lock' => 'Pirmiausia nustatykite programėlės užraktą — jis saugo įrenginio tapatybę.',
        'safety_number_changed' => 'Kitas įrenginys pasikeitė, kol lyginai. Prieš patvirtindamas dar kartą patikrink žemiau esančius žodžius.',
    ],
];
