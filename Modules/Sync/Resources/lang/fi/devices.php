<?php

declare(strict_types=1);

return [
    'heading' => 'Laitteet ja synkronointi',

    'enable_sync' => 'Ota synkronointi käyttöön',
    'enable_sync_help' => 'Jaa tietosi turvallisesti luotettujen laitteiden kesken. Vaatii sovelluslukon. Kun se on päällä, tietosi salataan eikä sovelluslukkoa voi enää poistaa käytöstä.',

    'app_lock_notice' => 'Aseta ensin sovelluslukko, niin voit ottaa synkronoinnin käyttöön.',
    'go_to_app_lock' => 'Siirry sovelluslukkoon',

    'identity_unreadable' => 'Tämän laitteen synkronointi-identiteetti luotiin toisella sovelluslukolla, eikä se enää aukea. Siihen asti laite ei voi synkronoida eikä muodostaa laiteparia. Palauttamalla tietokannan varmuuskopion, jolla se luotiin, se on jälleen luettavissa.',
    'identity_unreadable_replace_help' => 'Voit myös aloittaa alusta: laite saa uuden identiteetin, vanha jää käyttämättömänä talteen, ja aiemmin pariksi liitetyt laitteet on liitettävä uudelleen.',
    'identity_unreadable_replace' => 'Luo laitteelle uusi identiteetti',

    'encrypted_at_rest' => 'Tiedot salattu levylle',
    'encrypted_at_rest_scope' => 'Muistiinpanot, tapahtumakuvaukset sekä maksunsaajien nimet ja IBAN-tilinumerot salataan kirjanpidossa sovelluslukituksen tunnuslauseella. Summia, päivämääriä eikä oman tilisi nimeä ja IBANia ei salata. Hakemisto säilyttää oman luettavan kopionsa siitä, kenelle maksat, tapahtumakuvauksistasi ja veromuistiinpanoistasi, ja jotkin kauppiaiden nimet näkyvät selkokielisenä muualla tietokantatiedostossa.',
    'on' => 'Päällä',
    'securing' => 'Suojataan tietojasi…',
    'do_not_close' => 'Älä sulje tätä ikkunaa.',
    'encryption_progress_aria' => 'Salauksen edistyminen',
    'not_encrypted_offer' => 'Tietojasi ei ole salattu levossa. Salaus piilottaa kenelle maksat, jos laite katoaa tai varastetaan — summat, päivämäärät ja hakuindeksi pysyvät luettavina.',
    'enable_encryption' => 'Ota salaus käyttöön',

    'your_devices' => 'Laitteesi',

    // Asetukset säilyttää osoittimen siirrettyyn näkymään; itse osio
    // sijaitsee nyt /sync-sivulla tilan ja synkronointitoiminnon kanssa.
    'moved_help' => 'Laiteparit, laitenimet ja salaus löytyvät nyt synkronoinnin tilan yhteydestä.',
    'moved_cta' => 'Avaa Synkronointi ja laite',
    'device_name' => 'Laitteen nimi',
    'save' => 'Tallenna',
    'peer_default_name' => 'Paritettu laite',
    'rename_device' => 'Nimeä laite uudelleen',
    'this_device' => 'Tämä laite',
    'removed' => 'Poistettu',
    'confirmed' => 'Vahvistettu',
    'awaiting_confirmation' => 'Odottaa vahvistusta',
    'safety_number_words' => 'Turvanumeron sanat:',
    'paired' => 'Paritettu',
    'remove_aria' => 'Poista :name',
    'remove' => 'Poista',
    'pair_new_device' => 'Parita uusi laite',

    'pairing_waiting' => 'Viimeistele laiteparin muodostus laitteen :name kanssa',
    'pairing_waiting_help' => 'Molempien näyttöjen on näytettävä samat kuusi sanaa, ennen kuin laitepari on voimassa. Avaa se uudelleen ja vertaa niitä.',
    'pairing_waiting_resume' => 'Jatka laiteparin muodostusta',
    'pairing_waiting_lock_override' => 'Lukituksen avaaminen avaa tämän laiteparin uudelleen sen sijaan, että antaisi sen vanhentua, joten se kestää asettamaasi sovelluslukituksen aikakatkaisua pidempään. Se päättyy, kun viimeistelet tai peruutat sen.',

    'relay_endpoint' => 'Välityspalvelimen osoite',
    'relay_endpoint_help' => 'Valinnainen. Kun tämä on asetettu, verkon ulkopuolella olevat laitteet synkronoivat tämän välityspalvelimen kautta. Jätä tyhjäksi, jos haluat vain LAN&#8209;suoran yhteyden.',
    'relay_endpoint_aria' => 'Välityspalvelimen URL-osoite',
    'relay_insecure_warning' => 'Tämä välityspalvelimen osoite käyttää salaamatonta HTTP-yhteyttä. Vaikka välityspalvelin ei koskaan pura tietojesi salausta, suojaamaton yhteys paljastaa salattujen viestien koot ja ajoituksen verkkoa tarkkaileville. Käytä <strong>https://</strong>-osoitetta parhaan yksityisyyden vuoksi.',

    'enable_at_rest' => 'Ota levysalaus käyttöön',
    'enable_at_rest_body' => 'Tietosi salataan sovelluslukkosi salalauseella. Ennen siirtoa luodaan automaattisesti varmuuskopio.',
    'no_recovery_warning' => 'Jos menetät sovelluslukkosi salalauseen eikä sinulla ole varmuuskopiota tai muuta luotettua laitetta, tietojasi ei voi palauttaa.',
    'recover_help' => 'Saat pääsyn takaisin parittamalla tämän laitteen uudelleen toiselta luotetulta laitteelta tai käyttämällä omaa salattua varmuuskopiotasi.',
    'amounts_plaintext' => 'Summia ei salata levylle — saldot ja loppusummat pysyvät luettavina, jotta kuukausisummasi lasketaan yhä oikein.',
    'search_plaintext' => 'Hakemisto säilyttää kauppias- ja kuvaustekstistä selkokielisen kopion, jotta kokotekstihaku toimii edelleen.',
    'keep_unencrypted' => 'Pidä tiedot salaamattomina',
    'encryption_enabled' => 'Salaus otettu käyttöön',
    'encryption_enabled_scope' => 'Muistiinpanot, kuvaukset ja se, kenelle maksat, on nyt salattu sovelluslukituksen tunnuslauseella. Summat, päivämäärät ja hakemisto pysyvät luettavina.',
    'done_encryption_enabled' => 'Valmis — salaus otettu käyttöön',
    'encryption_failed' => 'Salauksen käyttöönotto epäonnistui',
    'encryption_failed_body' => 'Tietojasi ei muutettu. Varmuuskopiosi säilyi.',
    'close_no_changes' => 'Sulje — muutoksia ei tehty',

    'remove_this_device' => 'Poista tämä laite',
    'removing' => 'Poistetaan:',
    'remove_rotates_key' => 'Tämän laitteen poistaminen kierrättää salausavaimen, joten laite ei saa enää päivityksiä.',
    'remove_cannot_erase' => 'Se ei voi pyyhkiä tietoja, jotka ovat jo kyseisellä laitteella. Jos laite on kadonnut tai varastettu, käsittele sen sisältämiä tietoja paljastuneina.',
    'remove_device' => 'Poista laite',
    'keep_device' => 'Säilytä laite',
    'rotating_key' => 'Kierrätetään salausavainta…',

    'flash' => [
        'app_lock_first' => 'Aseta ensin sovelluslukko, niin voit ottaa synkronoinnin käyttöön.',
        'enable_failed' => 'Synkronoinnin käyttöönotto epäonnistui. Varmista, että sovelluslukkosi on aktiivinen, ja yritä uudelleen.',
        'identity_replaced' => 'Laitteella on uusi synkronointi-identiteetti. Liitä muut laitteesi uudelleen pariksi.',
        'identity_replace_failed' => 'Vanhaa laiteidentiteettiä ei voitu siirtää sivuun. Yritä uudelleen.',
        'cannot_remove_self' => 'Et voi poistaa tätä laitetta — se on laite, jota käytät juuri nyt.',
        'remove_failed' => 'Laitteen poistaminen epäonnistui. Yritä uudelleen.',
        'app_lock_first_settings' => 'Aseta ensin sovelluslukko, niin voit muuttaa synkronoinnin asetuksia.',
        'relay_cleared' => 'Välityspalvelimen osoite tyhjennetty.',
        'relay_saved' => 'Välityspalvelimen osoite tallennettu.',
        'relay_save_failed' => 'Välityspalvelimen osoitteen tallennus epäonnistui: :message',
    ],
    'app_lock_permanent' => 'Kun tietosi on kerran salattu, sovelluslukkoa ei voi enää poistaa käytöstä — se pitää ainoaa avainta, eikä salaamattomaan ole paluuta.',
    'backlog_heading' => 'Odottaa lisäämistä',
    'backlog_deferred' => 'Tämä laite on vastaanottanut tietoja toiselta laitteelta eikä ole vielä lisännyt niitä kirjanpitoosi. Mitään ei katoa — ne otetaan käyttöön automaattisesti, yleensä hetkessä.',
    'backlog_awaiting_key' => 'Tämä laite on vastaanottanut tietoja, joihin sillä ei vielä ole avainta. Mitään ei katoa. Avaa sovellus laiteparilla samaan aikaan kun tämä on auki, jotta laitteet voivat yhdistää ja avain voidaan lähettää.',
];
