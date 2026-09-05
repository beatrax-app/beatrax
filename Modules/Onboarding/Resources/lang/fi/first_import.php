<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Tarkista ja kirjaa',
    'h1' => 'Tarkista kaikki löytämämme',

    'lede_counts' => ':transactions :sources.',
    'source' => ':count lähteestä|:count lähteestä',
    'lede_confirm' => 'Vahvista alkusaldot ja kirjaa sitten.',

    'empty' => 'Ei vielä tarkistettavaa. Pudota tiliote aiemmissa vaiheissa, niin tapahtumasi näkyvät tässä.',

    'sb_eyebrow_label' => '🧮 ALKUSALDOT ·',
    'account_detected' => ':count TILI TUNNISTETTU|:count TILIÄ TUNNISTETTU',
    'sb_lede' => 'Tunnistimme kunkin tilin alkusaldon. Vahvista tai muokkaa ennen kirjausta.',

    'txn' => ':count tapahtuma|:count tapahtumaa',
    'to_commit' => 'kirjattavana ·',
    'already_imported' => ':count jo tuotu|:count jo tuotu',
    'commit_committing' => 'Kirjataan…',
    'commit_count' => 'Kirjaa kaikki (:count tapahtuma) →|Kirjaa kaikki (:count tapahtumaa) →',
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
        'row' => ':count RIVI|:count RIVIÄ',
        'badge_ready' => '✓ VALMIS',
        'badge_empty' => 'TYHJÄ',
        'badge_error' => 'VAATII UUDELLEENLÄHETYKSEN',
        'error_body' => 'Emme pystyneet lukemaan kaikkia tämän lähteen tiedostoja. Kokeile toista tiedostoa →',
        'left_out' => 'Yksi tiedosto jätettiin pois, joten vain loput tallennetaan: :reason|:count tiedostoa jätettiin pois, joten vain loput tallennetaan: :reason',
        'rows_skipped' => 'Joitakin rivejä ei voitu lukea, ja ne ohitetaan: :reason',
        'empty_body' => 'Tämä tiliote on tyhjä.',
        'col_date' => 'Päivä',
        'col_type' => 'Tyyppi',
        'col_counterparty' => 'Vastapuoli',
        'col_amount' => 'Summa',
        'load_more' => 'Lataa lisää (:remaining jäljellä)',
        'rows_shown' => ':count rivi näkyvissä|:count riviä näkyvissä',
    ],
];
