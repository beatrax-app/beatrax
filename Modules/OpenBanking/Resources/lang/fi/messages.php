<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Asetukset',
        'heading' => 'Pankkiyhteys',
        'subtitle' => 'Hae tapahtumat automaattisesti ASN- tai SNS-pankista Enable Bankingin kautta. Se on kolmannen osapuolen PSD2-tilitietopalvelu. Oletuksena pois päältä.',
        'toggle_label' => 'Ota pankkiyhteys käyttöön',
        'toggle_connected' => 'Yhdistetty pankkiin :bank Enable Bankingin kautta.',
        'toggle_off_help' => 'Oletuksena pois päältä. Vaatii kertaluonteisen hyväksynnän ja ohjatun käyttöönoton.',
        'reconfirm_body' => 'Hyväksyntäsi vanheni ennen kuin yhteys ehti valmistua. Vahvista uudelleen, niin pankkiyhteys saadaan käyttöön.',
        'reconfirm_button' => 'Vahvista uudelleen ja viimeistele',
    ],

    'status_row' => [
        'heading' => 'Pankkiyhteys',
        'manage' => 'Hallitse pankkiyhteyttä',
        'not_connected' => 'Ei yhdistettyä pankkia. Yhdistä pankki, niin tapahtumat tuodaan automaattisesti.',
        'expired' => 'Suostumus vanhentunut — yhdistä uudelleen.',
        'connected' => 'Yhdistetty pankkiin :bank Enable Bankingin kautta. Viimeksi synkronoitu :when.',
        'never' => 'ei koskaan',
    ],

    'transparency' => [
        'aggregator_label' => 'Palveluntarjoaja',
        'bank_label' => 'Pankki',
        'consent_status_label' => 'Suostumuksen tila',
        'pill_expired' => 'Vanhentunut — yhdistä uudelleen',
        'pill_expiring' => 'Vanhenee pian',
        'pill_connected' => 'Yhdistetty',
        'whats_fetched_label' => 'Mitä haetaan',
        'whats_fetched' => 'Kirjatut tapahtumat ja saldot, viimeiset 90 päivää',
        'last_successful_sync_label' => 'Viimeisin onnistunut synkronointi',
        'never' => 'Ei koskaan',
        'last_attempt_label' => 'Viimeisin yritys',
        'last_attempt_failed' => ':when — epäonnistui (:reason)',
        'reason_consent_expired' => 'suostumus vanhentunut',
        'reason_error' => 'virhe',
        'disconnect_button' => 'Katkaise yhteys',
    ],

    'consent_banner' => [
        'heading' => 'Suostumus vanhentunut — yhdistä uudelleen',
        'body' => 'Viimeisin onnistunut synkronointi oli :when. Yhdistä uudelleen, niin automaattinen synkronointi jatkuu.',
        'never' => 'ei koskaan',
        'reconnect' => 'Yhdistä uudelleen',
    ],

    'sync' => [
        'review_import' => 'Tarkista tuonti',
        'reconnect_first' => 'Yhdistä ensin uudelleen',
        'auto_caption' => 'Synkronoi automaattisesti kerran päivässä.',
        'sync_now' => 'Synkronoi nyt',

        'consent_expired' => 'Suostumus vanhentunut — yhdistä uudelleen.',
        'unavailable' => 'Enable Banking ei ole hetkellisesti käytettävissä. Yritä pian uudelleen.',
        'new_found' => 'Löytyi :count uusi tapahtuma.|Löytyi :count uutta tapahtumaa.',
        'none' => 'Ei uusia tapahtumia.',
    ],

    'disconnect' => [
        'heading' => 'Katkaistaanko pankkiyhteys?',
        'body' => 'Tämä poistaa tallennetut Enable Banking -tunnuksesi ja suostumuksesi. Automaattinen synkronointi loppuu heti. Beatraxiin jo tuodut tapahtumat säilyvät ennallaan.',
        'confirm' => 'Katkaise yhteys',
        'cancel' => 'Pidä yhteys',
    ],

    'ics' => [
        'section_label' => 'Tiedostotuonti — tunnuksia ei tallenneta',
        'heading' => 'ICS-luottokorttitiliote',
        'step_login' => 'Kirjaudu sisään',
        'step_download' => 'Lataa tiliote',
        'pdf_statement' => 'PDF-tiliote',
        'step_drop' => 'Pudota se alle',
        'drop_zone_label' => 'Pudota tiliotetiedosto tähän',
        'drop_zone_hint' => 'tai selaa tiedosto',
        'browse_aria' => 'Selaa ICS-tiliotetiedosto',
        'import_button' => 'Tuo tiliote',
        'validation' => [
            'required' => 'Pudota Mijn ICS -palvelusta lataamasi ICS-tiliote.',
            'max' => 'Tiedosto on liian suuri. ICS-PDF-tiliotteet ovat yleensä alle 1 Mt.',
            'extensions' => 'Tämä ei ole PDF. Mijn ICS vie tiliotteet vain PDF-muodossa.',
        ],
        'could_not_read' => 'Tiedostoa :filename ei voitu lukea. Koko virhe löytyy polusta /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Ennen kuin yhdistät kolmannen osapuolen',
        'body' => 'Pankkiyhteyden käyttöönotto lähettää pankkikirjautumisesi suostumuksen ja sen jälkeen tapahtuma- ja saldotietosi suoraan tältä laitteelta Enable Bankingille ja pankillesi. Beatrax ei ylläpidä palvelinta, joka näkisi nämä tiedot — mutta Enable Banking ja pankkisi näkevät ne. Tämä poikkeaa kaikista muista Beatraxin tuontitavoista, jotka eivät lähetä tietoja minnekään.',
        'acknowledge' => 'Ymmärrän, että tapahtumatietoni jaetaan Enable Bankingin ja pankkini kanssa.',
        'confirm' => 'Ota pankkiyhteys käyttöön',
        'cancel' => 'Peruuta',
    ],

    'wizard' => [
        'heading' => 'Yhdistä pankkisi',
        'intro' => 'Beatrax käyttää omaa Enable Banking -sovellustasi, joten tunnuksesi eivät päädy jaetulle palvelimelle. Tämä tehdään kerran kutakin pankkia kohden.',

        'step1_title' => 'Luo paikallinen avainpari',
        'step1_body' => 'Beatrax luo RSA-avainparin tällä laitteella. Yksityinen avain ei poistu laitteelta.',
        'generate_keypair' => 'Luo avainpari',
        'public_key_label' => 'Julkinen avain',
        'copy_public_key' => 'Kopioi julkinen avain',
        'copied' => 'Kopioitu',
        'redirect_uri_label' => 'Uudelleenohjaus-URI',
        'copy_redirect_uri' => 'Kopioi uudelleenohjaus-URI',

        'step2_title' => 'Rekisteröi sovellus Enable Bankingiin',
        'step2_body' => 'Avaa Enable Bankingin kehittäjäportaali, luo sovellus ja liitä siihen vaiheen 1 julkinen avain ja uudelleenohjaus-URI.',
        'open_portal' => 'Avaa Enable Banking -portaali ↗',

        'step3_title' => 'Liitä sovellustunnuksesi',
        'application_id_label' => 'Sovellustunnus',
        'step3_help' => 'Tämä tallennetaan paikalliseen tiedostoon tietokannan ulkopuolelle rajoitetuin käyttöoikeuksin, eikä se poistu tältä laitteelta.',

        'step4_title' => 'Valitse pankkisi',
        'via_enable_banking' => 'Enable Bankingin kautta',
        'other_institution' => 'Muu pankki',
        'institution_id_placeholder' => 'Pankin tunnus',

        'step5_title' => 'Viimeistele suostumus selaimessa',
        'step5_body' => 'Avaa pankkisi kirjautumis- ja suostumusnäkymä alta olevasta painikkeesta. Kirjaudu sisään ja tee mahdollinen kaksivaiheinen tunnistus, niin palaat tänne automaattisesti viimeistelemään pankkiyhteyden käyttöönoton.',

        'cancel' => 'Peruuta',
        'continue' => 'Jatka →',
        'continue_to_bank' => 'Jatka: :bank →',
        'your_bank' => 'oma pankkisi',

        'errors' => [
            'save_keypair_failed' => 'Avainparia ei voitu tallentaa levylle — tarkista salaisuushakemiston käyttöoikeudet ja yritä uudelleen.',
            'generate_failed' => 'Avainparia ei voitu luoda tällä laitteella — tarkista OpenSSL-määrityksesi.',
            'export_failed' => 'Luotua avainparia ei voitu viedä.',
            'read_public_failed' => 'Luotua julkista avainta ei voitu lukea.',
            'generate_first' => 'Luo avainpari ennen jatkamista.',
            'paste_application_id' => 'Liitä sovellustunnus Enable Bankingin portaalista ennen jatkamista.',
            'save_application_id_failed' => 'Sovellustunnusta ei voitu tallentaa levylle — tarkista salaisuushakemiston käyttöoikeudet ja yritä uudelleen.',
            'choose_bank' => 'Valitse pankki ennen jatkamista.',
        ],
    ],

    'alert' => [
        'reconsent' => 'Yhdistä pankkisi uudelleen',
    ],

    'errors' => [
        'wizard_incomplete' => 'Tee ensin ohjattu pankkiyhteyden käyttöönotto loppuun.',
        'no_bank_chosen' => 'Valitse pankki ennen yhdistämistä.',
        'no_consent_url' => 'Enable Banking ei palauttanut suostumusosoitetta.',
        'unparseable_consent_url' => 'Enable Banking palautti suostumusosoitteen, jota ei voi jäsentää.',
        'non_public_consent_host' => 'Enable Banking palautti suostumusosoitteen, jonka isäntä ei ole julkinen.',
        'unsafe_consent_url' => 'Enable Banking palautti turvattoman suostumusosoitteen.',
        'no_authorization_code' => 'Enable Bankingin paluukutsu ei sisältänyt valtuutuskoodia.',
        'no_session_id' => 'Enable Banking ei palauttanut istuntotunnusta.',
        'oauth_state_mismatch' => 'Tämä yhteyslinkki on vanhentunut tai jo käytetty. Aloita pankin yhdistäminen uudelleen.',
    ],
];
