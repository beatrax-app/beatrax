<?php

declare(strict_types=1);

return [
    'heading' => 'Seadmed ja sünkroonimine',

    'enable_sync' => 'Luba sünkroonimine',
    'enable_sync_help' => 'Jaga oma andmeid turvaliselt usaldusväärsete seadmete vahel. Nõuab rakenduse lukku.',

    'app_lock_notice' => 'Sünkroonimise lubamiseks määra kõigepealt rakenduse lukk.',
    'go_to_app_lock' => 'Ava rakenduse lukk',

    'encrypted_at_rest' => 'Andmed on kettal krüpteeritud',
    'encrypted_at_rest_scope' => 'Märkmed, tehingute kirjeldused ning saajate nimed ja IBAN-id on pearaamatus krüpteeritud sinu rakenduseluku paroolifraasiga. Summad, kuupäevad ning sinu enda konto nimi ja IBAN ei ole. Otsinguindeks hoiab omaenda loetavat koopiat sellest, kellele sa maksad, sinu tehingute kirjeldustest ja sinu maksumärkmetest, ning mõned kaupmeeste nimed on loetavad andmebaasifaili teistes kohtades.',
    'on' => 'Sees',
    'securing' => 'Kaitsen sinu andmeid…',
    'do_not_close' => 'Ära sulge seda akent.',
    'encryption_progress_aria' => 'Krüpteerimise edenemine',
    'not_encrypted_offer' => 'Teie andmed ei ole puhkeolekus krüpteeritud. Krüpteerimine varjab, kellele maksate, kui see seade kaob või varastatakse — summad, kuupäevad ja otsinguindeks jäävad loetavaks.',
    'enable_encryption' => 'Luba krüpteerimine',

    'your_devices' => 'Sinu seadmed',

    // Seaded viitavad ainult ümber kolinud vaatele; see osa asub
    // nüüd /sync all koos oleku ja sünkroonimise nupuga.
    'moved_help' => 'Sidumine, seadmenimed ja krüpteerimine asuvad nüüd koos sinu sünkroonimise olekuga.',
    'moved_cta' => 'Ava sünkroonimine ja seade',
    'device_name' => 'Seadme nimi',
    'save' => 'Salvesta',
    'peer_default_name' => 'Seotud seade',
    'rename_device' => 'Nimeta seade ümber',
    'this_device' => 'See seade',
    'removed' => 'Eemaldatud',
    'confirmed' => 'Kinnitatud',
    'awaiting_confirmation' => 'Ootab kinnitust',
    'safety_number_words' => 'Turvanumbri sõnad:',
    'paired' => 'Seotud',
    'remove_aria' => 'Eemalda :name',
    'remove' => 'Eemalda',
    'pair_new_device' => 'Seo uus seade',

    'relay_endpoint' => 'Relee aadress',
    'relay_endpoint_help' => 'Valikuline. Kui see on määratud, sünkroonivad võrguühenduseta seadmed selle relee kaudu. Jäta tühjaks, et sünkroonida ainult otse kohtvõrgus.',
    'relay_endpoint_aria' => 'Relee aadressi URL',
    'relay_insecure_warning' => 'See relee aadress kasutab tavalist HTTP-d. Kuigi relee ei dekrüpteeri kunagi sinu andmeid, paljastab ebaturvaline ühendus krüpteeritud andmete mahud ja ajastuse võrgu jälgijatele. Parima privaatsuse jaoks kasuta <strong>https://</strong> aadressi.',

    'enable_at_rest' => 'Luba kettal krüpteerimine',
    'enable_at_rest_body' => 'Sinu andmed krüpteeritakse rakenduse luku paroolifraasiga. Enne migratsiooni luuakse automaatselt varukoopia.',
    'no_recovery_warning' => 'Kui kaotad rakenduse luku paroolifraasi ning sul pole varukoopiat ega muud usaldusväärset seadet, ei ole sinu andmeid võimalik taastada.',
    'recover_help' => 'Ligipääsu taastamiseks seo see seade mõne teise usaldusväärse seadmega uuesti või kasuta oma eraldi krüpteeritud varukoopiat.',
    'amounts_plaintext' => 'Summasid kettal ei krüpteerita — jäägid ja kogusummad jäävad loetavaks, et sinu kuusummad ikka õigesti kokku liituksid.',
    'search_plaintext' => 'Otsinguindeks hoiab kaupmeeste ja kirjelduste teksti avatud kujul, et täistekstiotsing töötaks edasi.',
    'keep_unencrypted' => 'Jäta andmed krüpteerimata',
    'encryption_enabled' => 'Krüpteerimine on lubatud',
    'encryption_enabled_scope' => 'Märkmed, kirjeldused ja see, kellele sa maksad, on nüüd krüpteeritud sinu rakenduseluku paroolifraasiga. Summad, kuupäevad ja otsinguindeks jäävad loetavaks.',
    'done_encryption_enabled' => 'Valmis — krüpteerimine on lubatud',
    'encryption_failed' => 'Krüpteerimise seadistamine ebaõnnestus',
    'encryption_failed_body' => 'Sinu andmeid ei muudetud. Varukoopia jäi alles.',
    'close_no_changes' => 'Sulge — muudatusi ei tehtud',

    'remove_this_device' => 'Eemalda see seade',
    'removing' => 'Eemaldan:',
    'remove_rotates_key' => 'Selle seadme eemaldamine vahetab krüpteerimisvõtme, nii et see ei saa enam uuendusi.',
    'remove_cannot_erase' => 'See ei kustuta andmeid, mis on juba selles seadmes. Kui seade kadus või varastati, käsitle kõiki selles olnud andmeid avalikuks saanutena.',
    'remove_device' => 'Eemalda seade',
    'keep_device' => 'Jäta seade alles',
    'rotating_key' => 'Vahetan krüpteerimisvõtit…',

    'flash' => [
        'app_lock_first' => 'Sünkroonimise lubamiseks määra kõigepealt rakenduse lukk.',
        'enable_failed' => 'Sünkroonimist ei õnnestunud lubada. Veendu, et rakenduse lukk on aktiivne, ja proovi uuesti.',
        'cannot_remove_self' => 'Sa ei saa seda seadet eemaldada — see on seade, mida praegu kasutad.',
        'remove_failed' => 'Seadet ei õnnestunud eemaldada. Palun proovi uuesti.',
        'app_lock_first_settings' => 'Sünkroonimise seadete muutmiseks määra kõigepealt rakenduse lukk.',
        'relay_cleared' => 'Relee aadress on tühjendatud.',
        'relay_saved' => 'Relee aadress on salvestatud.',
        'relay_save_failed' => 'Relee aadressi ei õnnestunud salvestada: :message',
    ],
];
