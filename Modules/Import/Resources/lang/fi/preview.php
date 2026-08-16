<?php

declare(strict_types=1);

return [
    'page_title' => 'Esikatsele tuontia',
    'heading' => 'Esikatsele tuontia',
    'discard' => 'Hylkää tuonti',
    'confirm' => 'Vahvista tuonti',
    'subtitle' => 'Tarkista jäsennetyt rivit. Mitään ei tallenneta tilikirjaasi ennen kuin vahvistat.',

    'expired_html' => 'Esikatselu on vanhentunut. <a href="/imports/new" class="underline">Lähetä tiedosto uudelleen</a> ja yritä uudestaan.',

    'save_name' => 'Tallenna nimi',
    'account_name_label' => 'Tilin nimi',
    'account_placeholder' => 'esim. Pääsäästötili',
    'rename_aria' => 'Nimeä tämä vastapuoli uudelleen',

    'unknown_iban_prefix' => 'Löysimme tuntemattoman IBANin:',
    'unknown_iban_suffix' => 'Nimeä tämä tili.',

    'ics' => [
        'heading' => 'Nimeä ICS-korttitilisi.',
        'help' => 'Tuot ICS-tietoja nyt ensimmäistä kertaa. Anna tälle kortille nimi, niin se näkyy samana koko sovelluksessa.',
        'placeholder' => 'esim. ICS-kortti',
    ],

    'paypal' => [
        'heading' => 'Nimeä PayPal-tilisi.',
        'help' => 'Tuot PayPal-tietoja nyt ensimmäistä kertaa. Anna tälle lompakolle nimi, niin se näkyy samana koko sovelluksessa.',
        'placeholder' => 'esim. PayPal',
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

    'chain' => [
        'heading' => 'Ratkaistaan ketjuja…',
        'pending' => 'Jonossa. Ketjujen ratkaisu alkaa pian.',
        'running' => 'Yhdistetään rahoitusketjuja ja puretaan tiliotteen tilityksiä.',
        'failed_prefix' => 'Ketjujen ratkaisu epäonnistui:',
        'unknown_error' => 'tapahtui tuntematon virhe',
        'open_horizon' => 'Avaa Horizon',
        'failed_suffix' => 'ja yritä uudelleen tai tarkastele tilannetta.',
    ],

    'errors' => [
        'iban_not_in_preview' => 'Tämä IBAN ei kuulu nykyiseen esikatseluun.',
    ],
];
