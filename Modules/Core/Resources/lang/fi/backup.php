<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Tämä sovellus ei voi luovuttaa tiedostoa laitteellesi, joten salattu varmuuskopio tehdään työpöytäsovelluksessa. Paritä tämä laite pitääksesi ne synkassa.',
        'unavailable' => 'Salatut varmuuskopiot ovat käytettävissä työpöytäversiossa (SQLite). Palvelintietokannassa käytä tietokannan omia varmuuskopiointityökaluja.',
        'intro' => 'Lataa salalauseella salattu kopio koko tietokannastasi — sitä voi turvallisesti säilyttää ulkoisella levyllä tai pilvitallennuksessa, koska se on lukukelvoton ilman salalausetta (kvanttiturvallinen XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Salalause',
        'confirm_passphrase' => 'Vahvista salalause',
        'keep_safe' => 'Säilytä salalause turvassa — varmuuskopiota ei voi palauttaa ilman sitä.',
        'submit' => 'Lataa salattu varmuuskopio',
        'preparing' => 'Valmistellaan…',
    ],

    'restore' => [
        'heading' => 'Palauta varmuuskopiosta',

        'intro_html' => 'Korvaa nykyinen tietokantasi salatulla varmuuskopiolla. Tiedosto puretaan ja tarkistetaan ennen kuin mikään muuttuu, ja nykyisistä tiedoistasi tallennetaan ensin tilannevedos — mutta tämä silti <strong class="text-slate-700 dark:text-slate-200">korvaa kaiken</strong>, joten toiminto on suojattu. Sinut kirjataan ulos, sillä myös kirjautumisesi on tietokannassa.',
        'restored' => 'Varmuuskopio palautettiin. Kirjaudu sisään käyttäjätunnuksella ja salasanalla, jotka olivat käytössä sitä tehtäessä.',
        'snapshot_saved_prefix' => 'Tilannevedos aiemmista tiedoistasi tallennettiin polkuun',
        'file_label' => 'Salattu varmuuskopio (.enc)',
        'uploading' => 'Lähetetään…',
        'passphrase' => 'Salalause',
        'confirm_prefix' => 'Kirjoita',
        'confirm_suffix' => 'vahvistaaksesi',
        'submit' => 'Palauta (korvaa nykyiset tiedot)',
        'restoring' => 'Palautetaan…',
    ],

    'errors' => [
        'passphrase_min' => 'Käytä salalausetta, jossa on vähintään :min merkki.|Käytä salalausetta, jossa on vähintään :min merkkiä.',
        'passphrase_mismatch' => 'Salalauseet eivät täsmää.',
        'download_sqlite_only' => 'Salattu lataus on käytettävissä vain SQLite-versiossa.',
        'create_failed' => 'Varmuuskopiota ei voitu luoda: :message',
        'confirm_phrase' => 'Kirjoita :phrase vahvistaaksesi — tämä korvaa nykyiset tietosi.',
        'choose_file' => 'Valitse palautettava salattu varmuuskopiotiedosto (.enc).',
        'upload_failed' => 'Tiedoston lataus ei valmistunut. Se voi olla liian suuri tälle laitteelle — työpöytäsovelluksessa palauttaminen hyväksyy suuremman varmuuskopion.',
        'enter_passphrase' => 'Anna salalause, jolla varmuuskopio salattiin.',
        'unreadable' => 'Lähetettyä tiedostoa ei voitu lukea. Yritä uudelleen.',
        'restore_wrong_passphrase' => 'Tuo salasanalause ei avannut tätä varmuuskopiota, eikä mitään ole muutettu. Kirjoita se uudelleen ja yritä uudestaan. Jos se on varmasti oikea, tiedostoa on muutettu sen tekemisen jälkeen — palauta silloin toisesta kopiosta.',
        'restore_not_a_backup' => 'Tämä tiedosto ei ole salattu Beatrax-varmuuskopio, joten palautettavaa ei ole eikä mitään ole muutettu. Valitse .enc-tiedosto, jonka sovellus kirjoitti varmuuskopiota tehtäessä.',
        'restore_contents_unreadable' => 'Varmuuskopio aukesi, mutta sen sisällä oleva tietokanta on vaurioitunut, joten sitä ei palautettu eikä mitään ole muutettu. Palauta vanhemmasta varmuuskopiosta.',
        'restore_could_not_read' => 'Varmuuskopiotiedostoa ei voitu lukea, joten palautusta ei suoritettu eikä mitään ole muutettu. Tarkista, että laitteessa on vapaata tilaa, ja yritä uudelleen.',
        'restore_not_supported' => 'Palautus toimii versiossa, joka pitää tietonsa yhdessä tiedostossa, eikä tämä ole sellainen, joten mitään ei ole muutettu. Palvelintietokannassa käytä sen omia palautustyökaluja.',
        'restore_failed' => 'Palautusta ei suoritettu eikä mitään ole muutettu. Yritä uudelleen — jos se epäonnistuu toistuvasti, sovelluksen loki kertoo, mikä sen pysäytti.',
    ],
];
