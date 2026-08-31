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
    'camera_off_no_search' => 'Kameros prieiga išjungta, o kito įrenginio paieška tinkle „iPhone“ dar neveikia — įvestas kodas neturi kuo jo rasti. Įrenginio nustatymuose vėl leisk kamerą Beatrax ir nuskaityk kito įrenginio kodą.',
    'no_search' => 'Kito įrenginio paieška tinkle „iPhone“ dar neveikia, tad įvestas kodas neturi ko rasti. Vietoj to nuskaityk kodą kamera — kamerai tinkle ieškoti nereikia.',
    'word_code_aria' => 'Įvesk žodinį kodą iš kito įrenginio',
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
    'done' => 'Atlikta',

    'errors' => [
        'relay_unreachable' => 'Nepavyksta pasiekti kito įrenginio. Įsitikink, kad abu yra tame pačiame tinkle ir kad kompiuteryje įjungtas sinchronizavimas.',
        'no_road_home' => 'Šis įrenginys negali ieškoti tinkle, o nuskaitytame kode nėra kito įrenginio adreso. Paprašyk jo parodyti naują kodą ir nuskaityk jį.',
        'invalid_code' => 'Šis kodas neteisingas arba nebegalioja. Paprašyk kitame įrenginyje sugeneruoti naują.',
        'code_incomplete' => 'Šis kodas nepilnas. Palygink jį su kitu įrenginiu ir įvesk visą.',
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
