<?php

declare(strict_types=1);

return [
    'page_title' => 'Tuo toiselta laitteelta',

    'heading' => 'Tuo toiselta laitteelta',
    'subtitle' => 'Määritä tälle puhelimelle oma tili ja lukko, ja muodosta sitten laitepari toisen laitteesi kanssa, niin saat historiasi tänne.',

    'username' => 'Käyttäjätunnus',
    'password' => 'Salasana',
    'password_help' => 'Vähintään 12 merkkiä — salasanaa ei voi nollata, vain palautuskoodit auttavat.',
    'confirm_password' => 'Vahvista salasana',

    'requirements_aria' => 'Salasanan vaatimukset',
    'req_length' => 'Vähintään 12 merkkiä',
    'req_match' => 'Salasanat täsmäävät',
    'req_met' => '(täyttyy)',
    'req_unmet' => '(ei vielä täyty)',

    'pin' => 'Sovelluslukon PIN-koodi',
    'pin_help' => '6-10 numeroa — avaa tämän laitteen.',
    'confirm_pin' => 'Vahvista PIN-koodi',
    'continue' => 'Jatka',

    'failed_heading' => 'Käyttöönotto jäi kesken',
    'failed_body' => 'Tilisi luotiin, mutta tämän laitteen käyttöönottoa ei saatu valmiiksi. Voit turvallisesti yrittää uudelleen.',
    'try_again' => 'Yritä uudelleen',

    'recovery_heading' => 'Tallenna nämä palautuskoodit',
    'recovery_body' => 'Tulosta ne tai tallenna turvalliseen paikkaan. Niitä ei näytetä uudelleen.',
    'already_heading' => 'Tämä laite on jo otettu käyttöön',
    'already_body' => 'Tilisi on olemassa tällä laitteella. Jatka laiteparin muodostukseen, niin saat sen yhteyteen muiden laitteidesi kanssa.',
    'recovery_download' => 'Lataa .txt-tiedostona',
    'recovery_copy' => 'Kopioi koodit',
    'recovery_copied' => 'Kopioitu',
    'recovery_copy_failed' => 'Kopiointi ei onnistunut. Kirjoita koodit muistiin.',
    'recovery_saved' => 'Tallennettu lataustesi joukkoon.',
    'recovery_share_title' => 'Beatraxin palautuskoodit',
    'recovery_share_message' => 'Säilytä ne turvallisessa paikassa.',
    'recovery_save_failed' => 'Tiedoston tallennus ei onnistunut. Kirjoita koodit muistiin.',
    'recovery_confirm' => 'Olen tallentanut nämä koodit turvalliseen paikkaan.',
    'continue_to_pairing' => 'Jatka laiteparin muodostukseen',

    'errors' => [
        'username_required' => 'Käyttäjätunnus on pakollinen.',
        'passwords_mismatch' => 'Salasanat eivät täsmää.',
        'password_length' => 'Käytä vähintään 12 merkkiä.',
        'pin_length' => 'PIN-koodissa on oltava vähintään 6 numeroa.',
        'pins_mismatch' => 'PIN-koodit eivät täsmää. Yritä uudelleen.',
        'session_expired' => 'Istuntosi vanheni ennen käyttöönoton valmistumista. Anna PIN-koodisi ja salasanasi uudelleen.',
        'retry_failed' => 'Tämän laitteen käyttöönottoa ei vieläkään saatu valmiiksi. Yritä uudelleen.',
        'account_failed' => 'Tiliä ei voitu luoda.',
    ],
];
