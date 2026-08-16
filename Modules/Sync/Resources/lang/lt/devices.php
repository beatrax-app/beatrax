<?php

declare(strict_types=1);

return [
    'heading' => 'Įrenginiai ir sinchronizavimas',

    'enable_sync' => 'Įjungti sinchronizavimą',
    'enable_sync_help' => 'Saugiai dalykis savo duomenimis tarp patikimų įrenginių. Reikia programėlės užrakto.',

    'app_lock_notice' => 'Kad įjungtum sinchronizavimą, pirma nustatyk programėlės užraktą.',
    'go_to_app_lock' => 'Eiti į programėlės užraktą',

    'encrypted_at_rest' => 'Duomenys šifruojami saugykloje',
    'encrypted_at_rest_help' => 'Tavo duomenys apsaugoti programėlės užrakto slaptafraze.',
    'on' => 'Įjungta',
    'securing' => 'Apsaugomi tavo duomenys…',
    'do_not_close' => 'Neuždaryk šio lango.',
    'not_encrypted_offer' => 'Tavo duomenys saugykloje nešifruojami. Nustatyk šifravimą, kad juos apsaugotum, jei šis įrenginys būtų pamestas ar pavogtas.',
    'enable_encryption' => 'Įjungti šifravimą',

    'your_devices' => 'Tavo įrenginiai',

    // Nustatymuose lieka nuoroda į perkeltą skiltį; pati skiltis
    // dabar yra /sync puslapyje kartu su būsena ir sinchronizavimo veiksmu.
    'moved_help' => 'Susiejimas, įrenginių pavadinimai ir šifravimas dabar yra kartu su sinchronizavimo būsena.',
    'moved_cta' => 'Atverti Sinchronizavimą ir įrenginį',
    'device_name' => 'Įrenginio pavadinimas',
    'save' => 'Išsaugoti',
    'peer_default_name' => 'Susietas įrenginys',
    'rename_device' => 'Pervadinti įrenginį',
    'this_device' => 'Šis įrenginys',
    'removed' => 'Pašalinta',
    'confirmed' => 'Patvirtinta',
    'awaiting_confirmation' => 'Laukiama patvirtinimo',
    'safety_number_words' => 'Saugos numerio žodžiai:',
    'paired' => 'Susieta',
    'remove_aria' => 'Pašalinti :name',
    'remove' => 'Pašalinti',
    'pair_new_device' => 'Susieti naują įrenginį',

    'relay_endpoint' => 'Retransliavimo adresas',
    'relay_endpoint_help' => 'Neprivaloma. Nurodžius, neprisijungę įrenginiai sinchronizuojasi per šį retransliatorių. Palik tuščią, jei nori tik tiesioginio LAN&#8209;ryšio.',
    'relay_endpoint_aria' => 'Retransliavimo adreso URL',
    'relay_insecure_warning' => 'Šis retransliavimo adresas naudoja paprastą HTTP. Nors retransliatorius tavo duomenų niekada neiššifruoja, nesaugus ryšys atskleidžia šifruotų duomenų dydžius ir laiką tinklo stebėtojams. Geriausią privatumą užtikrina <strong>https://</strong> adresas.',

    'enable_at_rest' => 'Įjungti šifravimą saugykloje',
    'enable_at_rest_body' => 'Tavo duomenys bus šifruojami naudojant programėlės užrakto slaptafrazę. Prieš perkėlimą automatiškai bus sukurta atsarginė kopija.',
    'no_recovery_warning' => 'Jei pamirši programėlės užrakto slaptafrazę ir neturėsi nei atsarginės kopijos, nei kito patikimo įrenginio, duomenų atkurti nebus įmanoma.',
    'recover_help' => 'Kad atgautum prieigą, iš naujo susiek šį įrenginį iš kito patikimo įrenginio arba naudok savo atskirą šifruotą atsarginę kopiją.',
    'amounts_plaintext' => 'Sumos saugykloje nešifruojamos — likučiai ir bendrosios sumos lieka skaitomi, kad mėnesio sumos ir toliau būtų sudedamos teisingai.',
    'search_plaintext' => 'Paieškos indeksas saugo neužšifruotą prekybininkų ir aprašymų teksto kopiją, kad veiktų viso teksto paieška.',
    'keep_unencrypted' => 'Palikti duomenis nešifruotus',
    'encryption_enabled' => 'Šifravimas įjungtas',
    'encryption_enabled_body' => 'Tavo duomenys dabar saugykloje šifruojami.',
    'done_encryption_enabled' => 'Atlikta — šifravimas įjungtas',
    'encryption_failed' => 'Šifravimo sąranka nepavyko',
    'encryption_failed_body' => 'Tavo duomenys nepakeisti. Atsarginė kopija išsaugota.',
    'close_no_changes' => 'Uždaryti — niekas nepakeista',

    'remove_this_device' => 'Pašalinti šį įrenginį',
    'removing' => 'Šalinama:',
    'remove_rotates_key' => 'Pašalinus šį įrenginį pakeičiamas šifravimo raktas, todėl jis nebegaus jokių atnaujinimų.',
    'remove_cannot_erase' => 'Tame įrenginyje jau esančių duomenų ištrinti neįmanoma. Jei šis įrenginys buvo pamestas ar pavogtas, laikyk visus jame buvusius duomenis atskleistais.',
    'remove_device' => 'Pašalinti įrenginį',
    'keep_device' => 'Palikti įrenginį',
    'rotating_key' => 'Keičiamas šifravimo raktas…',

    'flash' => [
        'app_lock_first' => 'Kad įjungtum sinchronizavimą, pirma nustatyk programėlės užraktą.',
        'enable_failed' => 'Nepavyko įjungti sinchronizavimo. Įsitikink, kad programėlės užraktas aktyvus, ir bandyk dar kartą.',
        'cannot_remove_self' => 'Šio įrenginio pašalinti negali — juo šiuo metu naudojiesi.',
        'remove_failed' => 'Nepavyko pašalinti įrenginio. Bandyk dar kartą.',
        'app_lock_first_settings' => 'Kad pakeistum sinchronizavimo nustatymus, pirma nustatyk programėlės užraktą.',
        'relay_cleared' => 'Retransliavimo adresas išvalytas.',
        'relay_saved' => 'Retransliavimo adresas išsaugotas.',
        'relay_save_failed' => 'Nepavyko išsaugoti retransliavimo adreso: :message',
    ],
];
