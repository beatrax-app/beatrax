<?php

declare(strict_types=1);

return [
    'page_title' => 'Säännöt',
    'heading' => 'Säännöt',
    'intro' => 'Esiluokittele tapahtumat tuonnin yhteydessä. Säännöt koskevat kaikkia lähteitä — pankkia, korttia, PayPalia ja sähköpostikuitteja.',
    'device_local_note' => 'Säännöt pysyvät tässä laitteessa. Niitä ei jaeta muiden laitteidesi kanssa.',

    'reapply' => 'Käytä sääntöjä historiaan uudelleen',
    'reapplying' => 'Käytetään uudelleen…',
    'new_rule' => 'Uusi sääntö',

    'reapply_progress_lead' => 'Sääntöjä käytetään uudelleen…',
    'reapply_progress_of' => '/',
    'reapply_progress_trail' => 'tapahtumaa tarkistettu',

    'empty_heading' => 'Ei vielä sääntöjä',
    'empty_body' => 'Säännöt tunnistavat tapahtumia useilla ehdoilla ja tekevät kategoria-, vastapuoli-, muistiinpano- ja verotunnistemuutokset automaattisesti — tuonnin yhteydessä ja aina kun käytät niitä uudelleen olemassa olevaan historiaasi.',
    'empty_cta' => 'Luo ensimmäinen sääntösi',

    'col_priority' => 'Prioriteetti',
    'col_conditions' => 'Ehdot',
    'col_actions' => 'Toiminnot',
    'col_hits' => 'Osumat',
    'col_created' => 'Luotu',
    'col_row_actions' => 'Toiminnot',
    'inactive_badge' => 'Pois',
    'inactive_title' => 'Tämä sääntö ei ole käytössä. Sääntö kytkeytyy pois, kun sen osoittama luokka tai vastapuoli poistetaan.',

    'more_conditions' => '+:count muuta',

    'delete_confirm' => 'Poistetaanko?',
    'delete_yes' => 'Kyllä, poista',
    'cancel' => 'Peruuta',
    'edit' => 'Muokkaa',
    'delete' => 'Poista',
    'edit_aria' => 'Muokkaa sääntöä (prioriteetti :priority)',
    'delete_aria' => 'Poista sääntö (prioriteetti :priority)',

    'footer_note' => 'Säännöt ja kauppiashistoria toimivat yhdessä. Säännön poistaminen ei tyhjennä sitä, mitä Beatrax on oppinut aiemmista luokitteluista — seuraava tuonti voi silti ehdottaa samaa kategoriaa historian perusteella.',

    'chip_category' => 'Kategoria: :path',
    'chip_counterparty' => 'Vastapuoli: :path',
    'chip_note' => 'Muistiinpano',
    'chip_tax_tag' => 'Verotunniste',

    'flash_deleted' => 'Sääntö poistettu.',
    'flash_not_found' => 'Sääntöä ei löytynyt (se on saatettu poistaa toisessa välilehdessä).',
    'flash_saved' => 'Sääntö tallennettu.',
    'flash_reapplying' => 'Sääntöjä käytetään uudelleen historiaasi…',
    'summary_no_changes' => 'Ei muutoksia — historiasi vastaa jo sääntöjäsi.',
    'summary_updated' => 'Päivitetty: :fields, :transactions.',
    'summary_fields' => ':count kenttä|:count kenttää',
    'summary_transactions' => ':count tapahtuma|:count tapahtumaa',
    'summary_reconciled_skipped' => ':count täsmäytetty tapahtuma ohitettiin.|:count täsmäytettyä tapahtumaa ohitettiin.',
];
