<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tarkista ja kirjaa',
    'h1' => 'Tarkista kaikki löytämämme',

    'lede_across' => 'tapahtumaa',
    'source' => 'lähteestä|lähteestä',
    'lede_confirm' => 'Vahvista alkusaldot ja kirjaa sitten.',

    'empty' => 'Ei vielä tarkistettavaa. Pudota tiliote aiemmissa vaiheissa, niin tapahtumasi näkyvät tässä.',

    'sb_eyebrow_label' => '🧮 ALKUSALDOT ·',
    'account_detected' => 'TILI TUNNISTETTU|TILIÄ TUNNISTETTU',
    'sb_lede' => 'Tunnistimme kunkin tilin alkusaldon. Vahvista tai muokkaa ennen kirjausta.',

    'txn' => 'tapahtuma|tapahtumaa',
    'to_commit' => 'kirjattavana ·',
    'already_imported' => 'jo tuotu',
    'commit_committing' => 'Kirjataan…',
    'commit_count' => 'Kirjaa kaikki (:count tapahtumaa) →',
    'commit_empty' => 'Kirjaa kaikki (—) →',
    'skip' => 'Ohita toistaiseksi',

    'errors' => [
        'nothing_to_commit' => 'Ei mitään kirjattavaa.',
        'commit_failed' => 'Emme pystyneet kirjaamaan tiliotteitasi. Mitään ei muutettu — yritä uudelleen.',
    ],

    'section' => [
        'from_prefix' => 'LÄHTEESTÄ ',
        'from_bank' => 'PANKKITILIOTTEESTASI',
        'from_ics' => 'ICS-KORTTITILIOTTEISTASI',
        'from_paypal' => 'PAYPALISTA',
        'row' => 'RIVI|RIVIÄ',
        'badge_ready' => '✓ VALMIS',
        'badge_empty' => 'TYHJÄ',
        'badge_error' => 'VAATII UUDELLEENLÄHETYKSEN',
        'badge_filtered' => 'JO TUOTU',
        'error_body' => 'Emme pystyneet lukemaan kaikkia tämän lähteen tiedostoja. Kokeile toista tiedostoa →',
        'empty_body' => 'Tämä tiliote on tyhjä.',
        'filtered_body' => 'Tämä tiliote on jo tuotu muualla — jätimme sen pois.',
        'col_date' => 'Päivä',
        'col_type' => 'Tyyppi',
        'col_counterparty' => 'Vastapuoli',
        'col_amount' => 'Summa',
        'load_more' => 'Lataa lisää (:remaining jäljellä)',
        'rows_shown' => ':count riviä näkyvissä',
    ],
];
