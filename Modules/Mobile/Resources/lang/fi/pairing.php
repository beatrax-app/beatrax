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
    'camera_off_no_search' => 'Kameran käyttöoikeus on pois päältä, eikä toisen laitteen etsiminen verkosta vielä toimi iPhonessa — joten kirjoitettu koodi ei löydä sitä itsekseen. Ota kameran käyttöoikeus Beatraxille takaisin käyttöön laitteesi asetuksista ja skannaa toisen laitteen koodi, tai lähetä koodi tässä, niin tämä näyttö kysyy, missä laite on.',
    'no_search' => 'Toisen laitteen etsiminen verkosta ei vielä toimi iPhonessa, joten kirjoitettu koodi ei löydä sitä itsekseen. Skannaa koodi kameralla — se ei tarvitse verkkohakua. Jos et voi skannata, lähetä koodi, niin tämä näyttö kysyy, missä toinen laite on.',
    'word_code_aria' => 'Syötä sanakoodi toiselta laitteelta',
    'initiator_address' => 'Missä toinen laite on?',
    'initiator_address_help' => 'Sen osoite tässä verkossa, hostina ja porttina. Tietokone näyttää sen kohdassa Laitteet ja synkronointi. Lähetä koodi uudelleen, kun olet syöttänyt sen.',
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
    'encryption_incomplete' => 'Laite on paritettu, mutta siihen tallennettujen tietojen salaus ei valmistunut. Tietoja ei säilytetä vielä salattuina.',
    'done' => 'Valmis',

    'errors' => [
        'relay_unreachable' => 'Toiseen laitteeseen ei saada yhteyttä. Varmista, että molemmat ovat samassa verkossa ja synkronointi on päällä työpöytäsovelluksessa.',
        'no_road_home' => 'Tämä laite ei voi etsiä verkosta, eikä skannaamassasi koodissa ole osoitetta toiseen laitteeseen. Pyydä sitä näyttämään uusi koodi ja skannaa se.',
        'invalid_code' => 'Tämä koodi on virheellinen tai vanhentunut. Pyydä toista laitetta luomaan uusi.',
        'already_under_way' => 'Tämä laite on jo ottanut koodin vastaan ja odottaa toisen laitteen vahvistusta. Jos sitä ei tule, pyydä uusi koodi ja käytä sitä.',
        'vouched_but_refused' => 'Toisella laitteella on koodi yhä, mutta tämä laite ei voinut ottaa sitä vastaan. Pyydä siltä uusi koodi ja käytä sitä.',
        'code_incomplete' => 'Tämä koodi ei ole kokonainen. Vertaa sitä toiseen laitteeseen ja syötä se kokonaan.',
        'initiator_address_invalid' => 'Tuo ei ole osoite, johon tämä laite voi soittaa. Anna se hostina ja porttina, esimerkiksi 192.168.1.20:8100.',
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
