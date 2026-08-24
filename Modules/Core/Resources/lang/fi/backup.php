<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'Tämä puhelin ei voi tallentaa sovelluksen antamaa tiedostoa, joten salattu varmuuskopio tehdään työpöytäsovelluksessa. Paritä tämä laite pitääksesi ne synkassa.',
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

        'intro_html' => 'Korvaa nykyinen tietokantasi salatulla varmuuskopiolla. Tiedosto puretaan ja tarkistetaan ennen kuin mikään muuttuu, ja nykyisistä tiedoistasi tallennetaan ensin tilannevedos — mutta tämä silti <strong class="text-slate-700 dark:text-slate-200">korvaa kaiken</strong>, joten toiminto on suojattu.',
        'restored' => 'Palautettu. Lataa sovellus uudelleen, niin näet palautetut tiedot.',
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
        'enter_passphrase' => 'Anna salalause, jolla varmuuskopio salattiin.',
        'unreadable' => 'Lähetettyä tiedostoa ei voitu lukea. Yritä uudelleen.',
    ],
];
