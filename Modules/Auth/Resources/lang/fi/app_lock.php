<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Tässä Beatraxin versiossa ei ole paikkaa avausavaimelle, joten biometristä avausta ei tarjota. Rajoitus ei ole laitteesi.',
    'error_enroll_unprotected' => 'Biometrinen avaus tarvitsee käyttöjärjestelmän avainsäilön, eikä tässä asennuksessa ole sellaista. Rekisteröinti jättäisi avausavaimen luettavaksi tietojesi viereen, joten sitä ei tarjota täällä.',
    'error_enroll_locked' => 'Avaa sovelluksen lukitus ennen käyttöönottoa.',
    'error_enroll_failed' => 'Laitteesi ei suostunut tallentamaan avainta. Biometrinen avaus ei ole käytettävissä.',
    'heading' => 'Sovelluslukko',

    'toggle_label' => 'Lukitse sovellus PIN-koodilla',
    'toggle_description' => 'Korvaa päivittäisen sisäänkirjautumisen PIN-koodilla. Istunnot pysyvät voimassa 30 päivää.',

    'setup_heading' => 'Aseta PIN-koodi, niin lukko otetaan käyttöön',
    'new_pin_label' => 'Uusi PIN-koodi (6–10 numeroa)',
    'confirm_pin_label' => 'Vahvista PIN-koodi',
    'account_password_label' => 'Tilin salasana',
    'account_password_note' => '(vaaditaan palautusavaimen luomiseen)',
    'account_password_placeholder' => 'Tilisi salasana',
    'set_pin' => 'Aseta PIN-koodi',

    'pin_row_label' => 'PIN-koodi',
    'pin_row_description' => 'Vaihda nykyinen PIN-koodisi.',
    'change_pin' => 'Vaihda PIN-koodi',
    'forgot_pin_link' => 'Unohditko PIN-koodin? Nollaa se tilisi salasanalla.',

    'biometric_enrolled_description' => 'Tämä laite on otettu käyttöön biometriseen avaukseen.',
    'biometric_enroll_description' => 'Ota tämä laite käyttöön, niin voit avata sen biometrisesti.',
    'remove' => 'Poista',
    'enroll' => 'Ota käyttöön',
    'biometric_unavailable' => 'Tämä Beatraxin versio ei voi tarjota biometristä avausta. PIN-koodisi on täällä ainoa avaustapa.',

    'deenroll_modal_heading' => 'Poista biometrinen avaus — vahvista PIN-koodilla',
    'current_pin_label' => 'Nykyinen PIN-koodi',
    'remove_biometric' => 'Poista biometrinen avaus',
    'keep_biometric' => 'Säilytä biometrinen avaus',

    'auto_lock' => 'Lukitse automaattisesti, kun on kulunut',
    // i18n-review: fi · auto_lock_note — ":window" is core::durations.seconds and
    // reads "30 sekuntia"; "30 sekunnin kuluessa" needs a genitive the shared key
    // cannot supply, so the window moved into its own "siihen kuluu" clause.
    // Whether a Finnish reader wants it back inside the frame is the call.
    'auto_lock_note' => 'Beatrax lukittuu, kun tämä aika on kulunut ilman toimintaa — ja aiemmin, jos poistut siitä: toiseen sovellukseen siirtyminen tai ikkunan piilottaminen tai sulkeminen lukitsee Beatraxin tästä asetuksesta riippumatta, ja siihen kuluu korkeintaan :window.',
    'idle_1' => '1 minuutti',
    'idle_5' => '5 minuuttia',
    'idle_15' => '15 minuuttia',
    'idle_30' => '30 minuuttia',

    'disable_modal_heading' => 'Poista sovelluslukko käytöstä — vahvista PIN-koodilla',
    'disable_lock' => 'Poista lukko käytöstä',
    'keep_lock' => 'Säilytä sovelluslukko',

    'forgot_modal_heading' => 'Nollaa PIN-koodi — vahvista tilin salasanalla',
    'forgot_modal_body' => 'Tilisi salasana palauttaa lukitusavaimen, joten PIN-koodin nollaus ei koskaan hävitä tietoja.',
    'confirm_new_pin_label' => 'Vahvista uusi PIN-koodi',
    'reset_pin' => 'Nollaa PIN-koodi',
    'cancel' => 'Peruuta',

    'change_modal_heading' => 'Vaihda PIN-koodi — vahvista nykyisellä PIN-koodilla',
    'keep_pin' => 'Säilytä PIN-koodi',

    'error_pin_too_short' => 'PIN-koodissa on oltava vähintään 6 numeroa.',
    'error_pin_digits' => 'PIN-koodissa on oltava :min–:max numeroa — vain numeroita.',
    'error_pin_mismatch' => 'PIN-koodit eivät täsmää. Yritä uudelleen.',
    'error_pin_required' => 'Anna PIN-koodisi.',
    'error_pin_incorrect' => 'Väärä PIN-koodi.',
    'error_account_password_required' => 'Anna tilisi salasana.',
    'error_account_password' => 'Väärä tilin salasana.',
    'change_pin_success' => 'Salausavaimesi on suojattu uudelleen uudella PIN-koodillasi.',
    'error_forgot_failed' => 'PIN-koodin nollaus epäonnistui — palautusavain ei ole käytettävissä.',
    'error_enable_first' => 'Ota PIN-lukko käyttöön ennen biometrisen avauksen käyttöönottoa.',
    'error_disable_blocked_by_encryption' => 'Muistiinpanosi ja vastapuolten tiedot on salattu avaimella, jota tämä sovelluslukitus pitää, joten lukituksen poistaminen jättäisi ne lukukelvottomiksi. Lukitus jää päälle — vaihda mieluummin PIN-koodisi.',
    'error_key_material_lost' => 'Tämä laite ei enää pidä avainta, joka avaa salatut tietosi, joten uusi PIN-koodi ei tee niistä taas luettavia. Muodosta laitepari sellaisen laitteen kanssa, jolla avain on yhä tallessa, niin saat ne takaisin.',
    'error_recovery_wrap_stale' => 'Tilin salasana ei enää avaa tätä sovelluslukkoa — se vaihdettiin lukon käyttöönoton jälkeen. PIN-koodisi toimii yhä, mutta sen takana ei ole mitään, jos unohdat sen. Liitä tilin salasana uudelleen nyt.',
    'relink_recovery' => 'Liitä tilin salasana uudelleen',
    'relink_modal_heading' => 'Liitä tilin salasana uudelleen — vahvista PIN-koodilla',
    'relink_recovery_success' => 'Tilin salasana voi taas palauttaa tämän sovelluslukon.',
];
