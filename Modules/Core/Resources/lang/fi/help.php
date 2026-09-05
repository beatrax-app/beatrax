<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Tietoa: :subject',
        'close' => 'Sulje',
    ],

    'page_title' => 'Missä tietoni ovat?',
    'intro' => 'Beatrax tallentaa kaiken tälle laitteelle. Mitään ei lähetetä palvelimelle, mitään ei synkronoida pilveen, mitään ei poistu tältä laitteelta ilman että viet sen itse.',

    'lives_here' => 'Tietosi sijaitsevat täällä',
    'copy' => 'Kopioi',
    'copied' => 'Kopioitu',

    'location' => [
        'database' => 'Tietokanta:',
        'artefacts_imports' => 'Tuodut tiliotteet:',
        'artefacts_mail' => 'Luettu sähköposti:',
        'artefacts_drop' => 'Valvottu kansio:',
        'backups' => 'Varmuuskopiot:',
        'secrets' => 'Liitäntöjen tunnukset:',
        'logs' => 'Lokit:',
    ],

    'copy_aria' => [
        'database' => 'Kopioi tietokannan polku leikepöydälle',
        'artefacts_imports' => 'Kopioi tuotujen tiliotteiden polku leikepöydälle',
        'artefacts_mail' => 'Kopioi luetun sähköpostin polku leikepöydälle',
        'artefacts_drop' => 'Kopioi valvotun kansion polku leikepöydälle',
        'backups' => 'Kopioi varmuuskopioiden polku leikepöydälle',
        'secrets' => 'Kopioi liitäntöjen tunnusten polku leikepöydälle',
        'logs' => 'Kopioi lokien polku leikepöydälle',
    ],

    'artefacts_heading' => 'Lähdeasiakirjasi eivät ole varmuuskopion sisällä',
    'artefacts_body' => 'Varmuuskopio sisältää tietokannan eikä mitään muuta. Tuomasi tiliotteet, skannerin hakema sähköposti ja valvottuun kansioon pudottamasi kuitit jäävät sinne, missä ovatkin, kolmeen yllä lueteltuun kansioon. Varmuuskopion siirtäminen turvaan ei kopioi niitä, joten täydellinen arkisto tarkoittaa myös noiden kansioiden mukaan ottamista — tai alla olevan Vie kaikki -toiminnon käyttöä, joka niputtaa ne varmuuskopion kanssa puolestasi.',

    'export_heading' => 'Vie kaikki',
    'export_body' => 'Yksi arkisto, jossa on salattu kopio tietokannastasi ja jokainen lähdeasiakirja, jonka olet Beatraxille antanut. Pura se minne haluat, ja asiakirjasi ovat siellä sellaisina kuin ne aina olivat, niissä kansioissa, joista ne tulivat.',
    'export_passphrase_label' => 'Tietokannan salasanalause',
    'export_confirm_label' => 'Toista salasanalause',
    'export_passphrase_hint' => 'Arkiston sisällä oleva tietokanta salataan tällä salasanalauseella eikä sitä saa auki ilman sitä, joten valitse jotain, joka on sinulla vielä myöhemminkin. Lähdeasiakirjasi menevät mukaan sellaisinaan, joten säilytä arkistoa paikassa, johon luotat.',
    'export_cta' => 'Vie kaikki ZIP-tiedostona',
    'export_working' => 'Arkistoa luodaan…',

    'delete_heading' => 'Tietojesi poistaminen',
    'delete_intro' => 'Tietosi ovat tiedostoja tällä laitteella, joten niiden poistaminen tarkoittaa noiden tiedostojen poistamista. Täällä ei ole painiketta, joka tekisi sen puolestasi, ja se on tarkoituksellista: historiasi on tiedostojärjestelmässä, ja painike, joka tyhjentäisi muutaman taulun mutta jättäisi tiedostot paikoilleen, olisi huonompi kuin ei mitään.',
    'delete_uninstall' => 'Beatraxin poistaminen ei poista tietojasi. Se on tarkoituksellista — vahingossa tehty poisto ei saa tuhota vuosien historiaa — joten kaikki alla oleva säilyy tällä laitteella, kunnes poistat sen itse.',
    'delete_list_intro' => 'Poista jokainen näistä, niin jälkiä ei jää:',
    'delete_journal_note' => 'Tietokannan vieressä on kaksi lokitiedostoa, :wal ja :shm. Tuoreimmat muutoksesi ovat niissä, kunnes ne kirjataan tietokantaan, joten poista kaikki kolme yhdessä.',
    'no_telemetry' => 'Telemetriaa, josta kieltäytyä, ei ole, eikä etätiliä, joka pitäisi sulkea.',
];
