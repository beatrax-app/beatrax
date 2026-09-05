<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Nustatymai',
        'heading' => 'Atviroji bankininkystė',
        'subtitle' => 'Automatiškai atsisiųsk operacijas iš ASN arba SNS per Enable Banking — trečiosios šalies PSD2 agregatorių. Pagal numatymą išjungta.',
        'toggle_label' => 'Įjungti atvirąją bankininkystę',
        'toggle_connected' => 'Prisijungta prie :bank per Enable Banking.',
        'toggle_off_help' => 'Pagal numatymą išjungta. Reikia vienkartinio patvirtinimo ir žingsnis po žingsnio sąrankos.',
        'credentials_unreadable' => 'Šiame įrenginyje išsaugotų atvirosios bankininkystės prisijungimo duomenų nepavyksta perskaityti, todėl Beatrax negali prisijungti prie tavo banko.',
        'credentials_unreadable_next' => 'Atlik žingsnis po žingsnio sąranką iš naujo, kad juos pakeistum. Jau importuotoms operacijoms tai neturi įtakos.',
        'reconfirm_body' => 'Tavo patvirtinimas nustojo galioti nespėjus užbaigti prisijungimo. Patvirtink iš naujo, kad užbaigtum atvirosios bankininkystės įjungimą.',
        'reconfirm_button' => 'Patvirtinti iš naujo ir užbaigti',
    ],

    'status_row' => [
        'heading' => 'Atviroji bankininkystė',
        'manage' => 'Tvarkyti atvirąją bankininkystę',
        'not_connected' => 'Nė vienas bankas neprijungtas. Prijunk, kad operacijos būtų importuojamos automatiškai.',
        'expired' => 'Sutikimas nustojo galioti — reikia prisijungti iš naujo.',
        'revoked' => 'Tavo bankas nutraukė ryšį — prisijunk iš naujo.',
        'connected' => 'Prisijungta prie :bank per Enable Banking. Paskutinis sinchronizavimas: :when.',
        'never' => 'niekada',
    ],

    'transparency' => [
        'aggregator_label' => 'Agregatorius',
        'bank_label' => 'Bankas',
        'consent_status_label' => 'Sutikimo būsena',
        'pill_expired' => 'Nebegalioja — prisijunk iš naujo',
        'pill_expiring' => 'Netrukus nustos galioti',
        'pill_connected' => 'Prisijungta',
        'pill_revoked' => 'Nutraukė tavo bankas — prisijunk iš naujo',
        'whats_fetched_label' => 'Kas atsiunčiama',
        'whats_fetched' => 'Įvykdytos operacijos ir likučiai, paskutinės 90 dienų',
        'last_successful_sync_label' => 'Paskutinis sėkmingas sinchronizavimas',
        'never' => 'Niekada',
        'last_attempt_label' => 'Paskutinis bandymas',
        'last_attempt_failed' => ':when — nepavyko (:reason)',
        'reason_consent_expired' => 'sutikimas nustojo galioti',
        'reason_error' => 'klaida',
        'reason_truncated' => 'sustabdyta anksti',
        'reason_nothing_imported' => 'nepavyko nieko įrašyti',
        'reason_consent_revoked' => 'nutraukė tavo bankas',
        'disconnect_button' => 'Atjungti',
    ],

    'consent_banner' => [
        'heading' => 'Sutikimas nustojo galioti — prisijunk iš naujo',
        'heading_revoked' => 'Tavo bankas nutraukė ryšį',
        'body' => 'Paskutinis sėkmingas sinchronizavimas: :when. Prisijunk iš naujo, kad automatinis sinchronizavimas būtų atnaujintas.',
        'body_revoked' => 'Tavo bankas arba Enable Banking atšaukė prieigą, todėl sinchronizavimas sustojo. Paskutinis sėkmingas sinchronizavimas buvo :when. Prisijunk iš naujo, kad jis tęstųsi.',
        'never' => 'niekada',
        'reconnect' => 'Prisijungti iš naujo',
    ],

    'sync' => [
        'review_import' => 'Peržiūrėti importą',
        'reconnect_first' => 'Pirma prisijunk iš naujo',
        'auto_caption' => 'Sinchronizuojama automatiškai kartą per dieną.',
        'sync_now' => 'Sinchronizuoti dabar',

        'consent_expired' => 'Sutikimas nustojo galioti — prisijunk iš naujo.',
        'unavailable' => 'Enable Banking laikinai neprieinama. Netrukus bandyk dar kartą.',
        'new_found' => 'Rasta :count nauja operacija.|Rastos :count naujos operacijos.|Rasta :count naujų operacijų.',
        'none' => 'Naujų operacijų nėra.',
        'none_importable' => 'Tavo bankas atsiuntė operacijų, bet nė vienos nepavyko įrašyti. Atverk importo peržiūrą, kad pamatytum kodėl.',
        'in_progress' => 'Sinchronizavimas jau vyksta. Pabandykite dar kartą po akimirkos.',
        'truncated' => 'Tavo banke buvo daugiau operacijų, nei vienas sinchronizavimas gali parsisiųsti, todėl šis paleidimas sustojo anksti. Niekas nebuvo įrašyta kaip sinchronizuota — kitas sinchronizavimas prasidės nuo to paties taško.',
    ],

    'disconnect' => [
        'heading' => 'Atjungti atvirąją bankininkystę?',
        'body' => 'Tai pašalins išsaugotus Enable Banking prisijungimo duomenis ir sutikimą. Automatinis sinchronizavimas iškart sustos. Į Beatrax jau importuotoms operacijoms tai neturi įtakos.',
        'confirm' => 'Atjungti',
        'cancel' => 'Palikti prijungtą',
    ],

    'ics' => [
        'section_label' => 'Failo importas — prisijungimo duomenys nesaugomi',
        'heading' => 'ICS kredito kortelės išrašas',
        'step_login' => 'Prisijunk',
        'step_download' => 'Atsisiųsk išrašą',
        'pdf_statement' => 'PDF išrašas',
        'step_drop' => 'Įkelk jį žemiau',
        'drop_zone_label' => 'Vilk išrašo failą čia',
        'drop_zone_hint' => 'arba pasirink failą',
        'browse_aria' => 'Pasirinkti ICS išrašo failą',
        'import_button' => 'Importuoti išrašą',
        'validation' => [
            'required' => 'Įkelk ICS išrašą, atsisiųstą iš Mijn ICS.',
            'max' => 'Šis failas per didelis. ICS PDF išrašai paprastai būna mažesni nei 1 MB.',
            'extensions' => 'Tai ne PDF. Mijn ICS eksportuoja tik PDF išrašus.',
        ],
        'could_not_read' => 'Nepavyko perskaityti :filename. Visą klaidą rasi /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Prieš prijungdamas trečiąją šalį',
        'body' => 'Įjungus atvirąją bankininkystę, tavo sutikimas prisijungti prie banko, o vėliau ir operacijų bei likučių duomenys siunčiami tiesiai iš šio įrenginio į Enable Banking ir tavo banką. Beatrax neturi serverio, kuris šiuos duomenis matytų, bet Enable Banking ir tavo bankas juos mato. Tuo tai skiriasi nuo visų kitų Beatrax importo būdų, kurie niekada niekur duomenų nesiunčia.',
        'acknowledge' => 'Suprantu, kad mano operacijų duomenimis bus dalijamasi su Enable Banking ir mano banku.',
        'confirm' => 'Įjungti atvirąją bankininkystę',
        'cancel' => 'Atšaukti',
    ],

    'wizard' => [
        'heading' => 'Prijunk savo banką',
        'intro' => 'Beatrax naudoja tavo paties Enable Banking programą, todėl prisijungimo duomenys niekada nepatenka į bendrą serverį. Tai vienkartinė sąranka kiekvienam bankui.',

        'step1_title' => 'Sugeneruok vietinę raktų porą',
        'step1_body' => 'Beatrax šiame įrenginyje sugeneruoja RSA raktų porą. Privatusis raktas iš jo niekada neišeina.',
        'generate_keypair' => 'Generuoti raktų porą',
        'public_key_label' => 'Viešasis raktas',
        'copy_public_key' => 'Kopijuoti viešąjį raktą',
        'copied' => 'Nukopijuota',
        'redirect_uri_label' => 'Peradresavimo URI',
        'copy_redirect_uri' => 'Kopijuoti peradresavimo URI',

        'step2_title' => 'Užregistruok programą Enable Banking portale',
        'step2_body' => 'Atverk Enable Banking kūrėjų portalą, sukurk programą ir įklijuok viešąjį raktą bei peradresavimo URI iš 1 žingsnio.',
        'open_portal' => 'Atverti Enable Banking portalą ↗',

        'step3_title' => 'Įklijuok savo programos ID',
        'application_id_label' => 'Programos ID',
        // i18n-review: lt · step3_help — "identifies your application to Enable
        // Banking" is reworded to "tells Enable Banking which application is
        // calling", because the brand takes no case in the direct form. A native
        // should say whether "identifikuoja tavo programą" reads better here.
        'step3_help' => 'Saugoma vietiniame faile už duomenų bazės ribų, kurį gali skaityti tik tu. Jis nurodo Enable Banking, kuri programa kreipiasi, todėl keliauja su kiekviena užklausa — privatusis raktas niekada.',

        'step4_title' => 'Pasirink savo banką',
        'via_enable_banking' => 'per Enable Banking',
        'other_institution' => 'Kita įstaiga',
        'institution_id_placeholder' => 'Įstaigos id',

        'step5_title' => 'Užbaik sutikimą naršyklėje',
        'step5_body' => 'Spustelėk žemiau, kad atvertum savo banko prisijungimo ir sutikimo langą. Prisijunk, atlik dviejų veiksnių patvirtinimą, ir būsi automatiškai grąžinti čia užbaigti atvirosios bankininkystės įjungimo.',
        // i18n-review: lt · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Palieski žemiau, kad atvertum savo banko prisijungimo ir sutikimo langą. Prisijunk, atlik dviejų veiksnių patvirtinimą, ir būsi automatiškai grąžinti čia užbaigti atvirosios bankininkystės įjungimo.',

        'cancel' => 'Atšaukti',
        'continue' => 'Tęsti →',
        'continue_to_bank' => 'Tęsti į :bank →',
        'your_bank' => 'savo banką',

        'errors' => [
            'save_keypair_failed' => 'Nepavyko įrašyti raktų poros į diską — patikrink paslapčių katalogo teises ir bandyk dar kartą.',
            'generate_failed' => 'Nepavyko sugeneruoti raktų poros šiame įrenginyje — patikrink savo OpenSSL konfigūraciją.',
            'export_failed' => 'Nepavyko eksportuoti sugeneruotos raktų poros.',
            'read_public_failed' => 'Nepavyko perskaityti sugeneruoto viešojo rakto.',
            'generate_first' => 'Prieš tęsdamas sugeneruok raktų porą.',
            'paste_application_id' => 'Prieš tęsdamas įklijuok programos ID iš Enable Banking portalo.',
            'save_application_id_failed' => 'Nepavyko įrašyti programos ID į diską — patikrink paslapčių katalogo teises ir bandyk dar kartą.',
            'choose_bank' => 'Prieš tęsdamas pasirink banką.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Pirmiausia užbaik atvirosios bankininkystės sąrankos vediklį.',
        'no_bank_chosen' => 'Prieš jungdamasis pasirink banką.',
        'no_consent_url' => 'Enable Banking negrąžino sutikimo URL.',
        'unparseable_consent_url' => 'Enable Banking grąžino nenuskaitomą sutikimo URL.',
        'non_public_consent_host' => 'Enable Banking grąžino neviešą sutikimo serverį.',
        'unsafe_consent_url' => 'Enable Banking grąžino nesaugų sutikimo URL.',
        'no_authorization_code' => 'Enable Banking atgalinis kvietimas negrąžino autorizacijos kodo.',
        'no_session_id' => 'Enable Banking negrąžino seanso id.',
        'oauth_state_mismatch' => 'Ši prisijungimo nuoroda nebegalioja arba jau panaudota. Pradėkite banko prijungimą iš naujo.',
    ],
];
