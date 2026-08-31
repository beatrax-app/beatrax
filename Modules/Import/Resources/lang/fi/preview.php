<?php

declare(strict_types=1);

return [
    'page_title' => 'Esikatsele tuontia',
    'heading' => 'Esikatsele tuontia',
    'discard' => 'Hylkää tuonti',
    'confirm' => 'Vahvista tuonti',
    'subtitle' => 'Tarkista jäsennetyt rivit. Mitään ei tallenneta tilikirjaasi ennen kuin vahvistat.',

    'already_imported' => 'Tämä tiedosto on jo tuotu.',

    'already_imported_link' => 'Näytä tuonnin tulos',

    'expired_html' => 'Esikatselu on vanhentunut. <a href="/imports/new" class="underline">Lähetä tiedosto uudelleen</a> ja yritä uudestaan.',

    'save_name' => 'Tallenna nimi',
    'account_name_label' => 'Tilin nimi',
    'account_placeholder' => 'esim. Pääsäästötili',
    'rename_aria' => 'Nimeä tämä vastapuoli uudelleen',

    'unknown_iban_prefix' => 'Löysimme tuntemattoman IBANin:',

    'unknown_account_prefix' => 'Löysimme tuntemattoman tilin:',
    'unknown_iban_suffix' => 'Nimeä tämä tili.',

    'ics' => [
        'name' => 'ICS-kortti',
        'heading' => 'Nimeä ICS-korttitilisi.',
        'help' => 'Tuot ICS-tietoja nyt ensimmäistä kertaa. Anna tälle kortille nimi, niin se näkyy samana koko sovelluksessa.',
        'placeholder' => 'esim. ICS-kortti',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Nimeä PayPal-tilisi.',
        'help' => 'Tuot PayPal-tietoja nyt ensimmäistä kertaa. Anna tälle lompakolle nimi, niin se näkyy samana koko sovelluksessa.',
        'placeholder' => 'esim. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Nimeä Google Play -tilisi.',
        'help' => 'Tuot Google Play -kuittia nyt ensimmäistä kertaa. Anna tälle tilille nimi, niin se näkyy samana koko sovelluksessa.',
        'placeholder' => 'esim. Google Play',
    ],

    'col_date' => 'Päivä',
    'col_funding_source' => 'Rahoituslähde',
    'col_counterparty' => 'Vastapuoli',
    'col_amount' => 'Summa',
    'col_status' => 'Tila',

    'status' => [
        'new' => 'Uusi',
        'new_title' => 'Lisätään tilikirjaasi.',
        'duplicate' => 'Kaksoiskappale',
        'duplicate_title' => 'Jo tuotu — ohitetaan.',
        'enriched' => 'Täydennetty',
        'enriched_title' => 'Olemassa oleva rivi päivitetään vahvemmalla lähdeviittauksella.',
        'error' => 'Virhe',
    ],

    'rows_shown' => 'Näytetyt rivit: :shown / :total',

    'show_more' => 'Näytä lisää rivejä',

    'errors' => [
        'app_locked' => 'Avaa sovelluksen lukitus tuodaksesi: salausavaimia ei voi käyttää lukittuna.',
        'archive_holds_one_message' => 'Tämä tiedosto on yksittäinen sähköpostiviesti, ei postilaatikkoarkisto, joten arkistona luettuna siinä ei ole mitään. Lataa se uudelleen muodolla Sähköpostiviesti.',
        'email_file_is_an_archive' => 'Tämä tiedosto on postilaatikkoarkisto: siinä on useampi kuin yksi viesti, ja yhtenä viestinä luettuna siitä otettaisiin vain ensimmäinen. Lataa se uudelleen muodolla Postilaatikkoarkisto.',
        'file_stopped_short' => 'Otsikkorivi täsmäsi, joten muoto on oikea. Lukeminen pysähtyi ennen tiedoston loppua. Sen aiheuttaa yksi lukukelvoton rivi, samoin tälle laitteelle liian suuri tiedosto. Kokeile lyhyempää ajanjaksoa.',
        'file_unreadable' => 'Tätä tiedostoa ei voitu lukea.',
        'file_unreadable_detail' => 'Sovellus ei voinut lukea tätä tiedostoa (:code). Täydet tiedot ovat sovelluslokissa; mainitse tämä koodi, jos ilmoitat ongelmasta.',
        'iban_not_in_preview' => 'Tämä IBAN ei kuulu nykyiseen esikatseluun.',
        'not_an_email_file' => 'Tämä tiedosto ei ole sähköpostiviesti eikä postilaatikkoarkisto, joten siinä ei ole mitään luettavaa kuittina. Valitse tuontityyppi ja muoto, jotka vastaavat tiedostoasi.',
        'pdf_has_no_text_layer' => 'Tässä PDF:ssä ei ole tekstiä — se on skannaus tai valokuva tiliotteesta, joten siitä ei ole mitään luettavaa. Lataa itse tiliote pankistasi tai käytä CSV-vientiä.',
        'pdf_password_protected' => 'Tämä PDF on salasanasuojattu, joten mikään lukija ei saa sitä auki. Tallenna PDF-katselimestasi suojaamaton kopio ja tuo se.',
        'pdf_reader_unavailable' => 'Tässä sovellusversiossa ei ole lainkaan PDF-lukijaa, joten PDF-tiliotetta ei voi avata täällä. Tuo tämä tiedosto toisella laitteella tai käytä pankkisi CSV-vientiä.',
        'row_belongs_to_another_statement' => 'Tämä rivi kuuluu tapahtumaan toisessa tiliotetiedostossa. Tuo myös se tiliote — ne luetaan yhdessä.',
        'row_unreadable' => 'Tätä riviä ei voitu lukea.',
        'row_unreadable_detail' => 'Sovellus ei voinut lukea tätä riviä (:code). Täydet tiedot ovat sovelluslokissa; mainitse tämä koodi, jos ilmoitat ongelmasta.',
        'unknown_account' => 'Tämä rivi kuuluu tilille, jolle et ole vielä antanut nimeä.',
    ],

    'receipts' => [
        'heading' => 'Tämä tiedosto luettiin sähköpostina',
        'saved' => 'Sen sisältö on lueteltu alla, ja jokainen viesti on tallennettu.',
        'none_imported' => 'Mikään näistä ei muuttunut tapahtumaksi, joten tilikirjaasi ei lisätty mitään.',
        'shown' => 'Näytetyt viestit: :shown / :total',
        'no_subject' => 'Ei aihetta',

        'state' => [
            'read' => 'Luettu maksuna — vahvista tämä tuonti, niin se päätyy tilikirjaasi.',
            'not_a_payment' => 'Ei ole maksu. Tämä viesti ilmoittaa jostakin sen sijaan, että vahvistaisi maksun.',
            'unreadable' => 'Tallennettu. Sovellus lukee tämän lähettäjän kuitteja, mutta viestistä ei löytynyt summaa, kauppiasta eikä viitettä.',
            'unknown_sender' => 'Tallennettu. Sovellus ei lue tämän lähettäjän kuitteja, joten se ei ottanut viestistä mitään.',
        ],
    ],

    'failed' => [
        'heading' => 'Tiedostoa ei voitu lukea',
        'no_rows' => 'Tiedostosta ei löytynyt tapahtumia, joten tuotavaa ei ole.',
        'nothing_read' => 'Mitään tiedostossa ei voitu lukea tapahtumana, joten tuotavaa ei ole.',
        'every_row' => 'Yhtäkään tiedoston riviä ei voitu lukea, joten tuotavaa ei ole. Jokainen rivi on lueteltu alla syineen.',
        'likely_cause' => 'Yleensä otsikkorivi ei vastaa valitsemaasi lähdettä. Tarkista pankki ja muoto latausnäytöllä tai lataa tiliote pankistasi uudelleen.',
        'truncated_heading' => 'Tiedostosta voitiin lukea vain osa',
        'truncated' => 'Luku pysähtyi kesken tiedoston. Sen jälkeistä ei luettu eikä tuoda.',
        'some_rows' => 'Joitakin rivejä ei voitu lukea. Ne on merkitty alle ja ohitetaan; vahvistus tuo loput.',
        'detail_label' => 'Mitä jäsennin ilmoitti:',
        'rows_read_label' => 'Luetut rivit',
        'rows_skipped_label' => 'Ohitetut rivit',
    ],
];
