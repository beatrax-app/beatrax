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
    'camera_off_no_search' => 'Kameran käyttöoikeus on pois päältä, eikä toisen laitteen etsiminen verkosta vielä toimi iPhonessa — kirjoitetulla koodilla ei siis ole mitään, millä löytää se. Salli kamera Beatraxille laitteesi asetuksista ja skannaa toisen laitteen koodi.',
    'no_search' => 'Toisen laitteen etsiminen verkosta ei vielä toimi iPhonessa, joten kirjoitetulla koodilla ei ole mitään löydettävää. Skannaa koodi sen sijaan kameralla — kamera ei etsi verkosta.',
    'word_code_aria' => 'Syötä sanakoodi toiselta laitteelta',
    'submit_code' => 'Lähetä koodi',
    'cancel' => 'Peruuta',
    'skip_import' => 'Jatka ilman tuontia',

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
        'no_road_home' => 'Tämä laite ei voi etsiä verkosta, eikä skannaamassasi koodissa ole osoitetta toiseen laitteeseen. Pyydä sitä näyttämään uusi koodi ja skannaa se.',
        'invalid_code' => 'Tämä koodi on virheellinen tai vanhentunut. Pyydä toista laitetta luomaan uusi.',
        'code_incomplete' => 'Tämä koodi ei ole kokonainen. Vertaa sitä toiseen laitteeseen ja syötä se kokonaan.',
        'code_not_accepted' => 'Mikään tämän verkon laite ei hyväksynyt koodia. Tarkista koodi ja se, näyttääkö toinen laite sitä yhä.',
        'no_peer_answered' => 'Mikään tässä verkossa ei vastannut koodiin. Tarkista, että synkronointi on käynnissä toisella laitteella, tai skannaa sen koodi kameralla — kamera ei etsi verkosta.',
        'no_peer_answered_ios' => 'Mikään tässä verkossa ei vastannut koodiin. Toisen laitteen etsiminen verkosta ei vielä toimi iPhonessa, joten skannaa sen koodi kameralla.',
        'no_peer_answered_camera_off' => 'Mikään tässä verkossa ei vastannut koodiin. Toisen laitteen etsiminen verkosta ei vielä toimi iPhonessa, ja kameran käyttöoikeus on pois päältä — salli siis kamera Beatraxille laitteesi asetuksista ja skannaa toisen laitteen koodi.',
        'rate_limited' => 'Liian monta yritystä. Odota minuutti ja yritä uudelleen.',
        'identity_locked' => 'Laitteesi identiteetti on lukittu. Avaa sovelluksen lukitus ja yritä uudelleen.',
        'identity_needs_lock' => 'Määritä ensin sovelluslukitus — se suojaa laitteesi identiteettiä.',
        'safety_number_changed' => 'Toinen laite muuttui vertailun aikana. Tarkista alla olevat sanat uudelleen ennen vahvistamista.',
    ],
];
