<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Paritettu laite',
    'page_title' => 'Muodosta laitepari',

    'scan_heading' => 'Parita tämä laite',
    'scan_subtitle' => 'Osoita kameralla toisella laitteella näkyvää koodia.',
    'camera_permission_pending' => 'Kameran käyttöoikeus on pois päältä. Salli se Beatraxille laitteesi asetuksista ja yritä uudelleen.',
    'open_camera' => 'Avaa kamera',
    'opening_camera' => 'Odotetaan kameran käyttöoikeutta…',
    'close_camera' => 'Sulje kamera',
    'viewfinder_aria' => 'Kameran etsin — osoita sillä toisen laitteesi koodia',
    'viewfinder_idle' => 'Kamera on pois päältä. Avaa se, niin voit skannata toisella laitteellasi näkyvän koodin.',
    'scan_prompt' => 'Skannaa toisen laitteesi koodi',
    'enter_code_instead' => 'Syötä koodi sen sijaan',

    'enter_heading' => 'Syötä koodi',
    'camera_off' => 'Kameran käyttöoikeus on pois päältä. Syötä sen sijaan toisen laitteen koodi.',
    'word_code_aria' => 'Syötä sanakoodi toiselta laitteelta',
    'submit_code' => 'Lähetä koodi',
    'cancel' => 'Peruuta',

    'confirm_heading' => 'Vertaa näitä sanoja toiseen laitteeseen',
    'safety_words_aria' => 'Turvanumeron sanat: :words',
    'confirm_body' => 'Molempien laitteiden on näytettävä täsmälleen samat sanat. Jos ne eroavat, napauta Peruuta — käynnissä voi olla välimieshyökkäys.',
    'awaiting_peer' => 'Odotetaan toisen laitteen vahvistusta...',
    'confirm_match' => 'Vahvista — ne täsmäävät',

    'success_heading' => 'Laitepari muodostettu',
    'success_body' => 'Tämä laite on nyt luotettu. Tietosi synkronoituvat heti, kun yhteys muodostuu.',
    'done' => 'Valmis',

    'errors' => [
        'relay_unreachable' => 'Toiseen laitteeseen ei saada yhteyttä. Varmista, että molemmat ovat samassa verkossa ja synkronointi on päällä työpöytäsovelluksessa.',
        'invalid_code' => 'Tämä koodi on virheellinen tai vanhentunut. Pyydä toista laitetta luomaan uusi.',
        'identity_locked' => 'Laitteesi identiteetti on lukittu. Avaa sovelluksen lukitus ja yritä uudelleen.',
        'identity_needs_lock' => 'Määritä ensin sovelluslukitus — se suojaa laitteesi identiteettiä.',
    ],
];
