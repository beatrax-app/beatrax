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
        'reconfirm_body' => 'Tavo patvirtinimas nustojo galioti nespėjus užbaigti prisijungimo. Patvirtink iš naujo, kad užbaigtum atvirosios bankininkystės įjungimą.',
        'reconfirm_button' => 'Patvirtinti iš naujo ir užbaigti',
    ],

    'status_row' => [
        'heading' => 'Atviroji bankininkystė',
        'manage' => 'Tvarkyti atvirąją bankininkystę',
        'not_connected' => 'Nė vienas bankas neprijungtas. Prijunk, kad operacijos būtų importuojamos automatiškai.',
        'expired' => 'Sutikimas nustojo galioti — reikia prisijungti iš naujo.',
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
        'whats_fetched_label' => 'Kas atsiunčiama',
        'whats_fetched' => 'Įvykdytos operacijos ir likučiai, paskutinės 90 dienų',
        'last_successful_sync_label' => 'Paskutinis sėkmingas sinchronizavimas',
        'never' => 'Niekada',
        'last_attempt_label' => 'Paskutinis bandymas',
        'last_attempt_failed' => ':when — nepavyko (:reason)',
        'reason_consent_expired' => 'sutikimas nustojo galioti',
        'reason_error' => 'klaida',
        'disconnect_button' => 'Atjungti',
    ],

    'consent_banner' => [
        'heading' => 'Sutikimas nustojo galioti — prisijunk iš naujo',
        'body' => 'Paskutinis sėkmingas sinchronizavimas: :when. Prisijunk iš naujo, kad automatinis sinchronizavimas būtų atnaujintas.',
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
        'step3_help' => 'Tai saugoma vietiniame faile už duomenų bazės ribų su griežtomis prieigos teisėmis ir niekada neišeina iš šio įrenginio.',

        'step4_title' => 'Pasirink savo banką',
        'via_enable_banking' => 'per Enable Banking',
        'other_institution' => 'Kita įstaiga',
        'institution_id_placeholder' => 'Įstaigos id',

        'step5_title' => 'Užbaik sutikimą naršyklėje',
        'step5_body' => 'Spustelėk žemiau, kad atvertum savo banko prisijungimo ir sutikimo langą. Prisijunk, atlik dviejų veiksnių patvirtinimą, ir būsi automatiškai grąžinti čia užbaigti atvirosios bankininkystės įjungimo.',

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

    'alert' => [
        'reconsent' => 'Prijunk banką iš naujo',
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
    ],
];
