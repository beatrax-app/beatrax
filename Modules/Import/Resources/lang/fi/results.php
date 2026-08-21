<?php

declare(strict_types=1);

return [
    'page_title' => 'Tuonti valmis',
    'heading' => 'Tuonti valmis',

    'summary' => 'Tuotiin :count tapahtuma|Tuotiin :count tapahtumaa',
    'summary_duplicates' => ' · ohitettiin :count kaksoiskappale| · ohitettiin :count kaksoiskappaletta',
    'summary_enriched' => ' · :count täydennetty',
    'summary_errors' => ' · :count virhe| · :count virhettä',

    'show_duplicates' => 'Näytä ohitetut kaksoiskappaleet (:count)',
    'duplicates_help' => 'Kaksoiskappaleet ovat rivejä, jotka ovat jo tilikirjassasi — ne ohitetaan huomautuksetta uudelleentuonnissa.',
    'show_errors' => 'Näytä virheet (:count)',
    'errors_help' => 'Virheet ovat rivejä, joita ei voitu jäsentää; niitä ei lisätty tilikirjaasi.',

    'upload_another' => 'Lähetä toinen tiliote',

    'issues' => [
        'row' => 'Rivi :row: :reason',
        'file' => 'Tiedostoa ei voitu lukea kokonaan: :reason',
        'duplicate' => 'Rivi :row oli jo kirjanpidossasi.',
        'more' => '+ :count ei lueteltu',
        'unknown_reason' => 'Syytä ei kirjattu.',
    ],
];
