<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'See rakendus ei saa sinu seadmele faili üle anda, seega tehakse krüptitud varukoopia lauaarvuti rakenduses. Seo see seade, et need püsiksid sünkroonis.',
        'unavailable' => 'Krüpteeritud varukoopiad on saadaval töölauaversioonis (SQLite). Serveri andmebaasi puhul kasuta andmebaasi enda varunduse tööriistu.',
        'intro' => 'Laadi alla paroolifraasiga krüpteeritud koopia kogu oma andmebaasist — seda on turvaline hoida välisel kettal või pilves, sest ilma paroolifraasita on see loetamatu (kvantkindel XChaCha20-Poly1305 + Argon2id).',
        'passphrase' => 'Paroolifraas',
        'confirm_passphrase' => 'Kinnita paroolifraas',
        'keep_safe' => 'Hoia paroolifraasi kindlas kohas — ilma selleta ei ole varukoopiat võimalik taastada.',
        'submit' => 'Laadi alla krüpteeritud varukoopia',
        'preparing' => 'Valmistan ette…',
    ],

    'restore' => [
        'heading' => 'Taasta varukoopiast',

        'intro_html' => 'Asenda praegune andmebaas krüpteeritud varukoopiaga. Fail dekrüpteeritakse ja kontrollitakse enne mis tahes muudatust ning praegustest andmetest salvestatakse esmalt taastamiseelne hetktõmmis — aga see <strong class="text-slate-700 dark:text-slate-200">kirjutab kõik üle</strong>, seega on see samm kaitstud. Sind logitakse välja, sest ka sinu sisselogimine on andmebaasis.',
        'restored' => 'Varukoopia taastati. Logige sisse kasutajanime ja parooliga, mis kehtisid selle tegemise ajal.',
        'snapshot_saved_prefix' => 'Sinu varasemate andmete hetktõmmis salvestati asukohta',
        'file_label' => 'Krüpteeritud varukoopia (.enc)',
        'uploading' => 'Laadin üles…',
        'passphrase' => 'Paroolifraas',
        'confirm_prefix' => 'Sisesta',
        'confirm_suffix' => 'kinnitamiseks',
        'submit' => 'Taasta (kirjutab praegused andmed üle)',
        'restoring' => 'Taastan…',
    ],

    'errors' => [
        'passphrase_min' => 'Kasuta vähemalt :min märgi pikkust paroolifraasi.|Kasuta vähemalt :min märgi pikkust paroolifraasi.',
        'passphrase_mismatch' => 'Kaks paroolifraasi ei kattu.',
        'download_sqlite_only' => 'Krüpteeritud allalaadimine on saadaval ainult SQLite-versioonis.',
        'create_failed' => 'Varukoopiat ei õnnestunud luua: :message',
        'confirm_phrase' => 'Kinnitamiseks sisesta :phrase — see asendab sinu praegused andmed.',
        'choose_file' => 'Vali taastamiseks krüpteeritud varukoopia fail (.enc).',
        'upload_failed' => 'Faili üleslaadimine ei lõppenud. See võib olla selle seadme jaoks liiga suur — töölauarakenduses taastamine võtab vastu suurema varukoopia.',
        'enter_passphrase' => 'Sisesta paroolifraas, millega varukoopia krüpteeriti.',
        'unreadable' => 'Üleslaaditud faili ei õnnestunud lugeda. Proovi uuesti.',
        'restore_wrong_passphrase' => 'See paroolifraas ei avanud seda varukoopiat ja midagi ei muudetud. Trüki see uuesti ja proovi veel. Kui see on kindlasti õige, on faili pärast loomist muudetud — taasta siis mõnest teisest koopiast.',
        'restore_not_a_backup' => 'See fail ei ole krüpteeritud Beatraxi varukoopia, seega pole midagi taastada ja midagi ei muudetud. Vali .enc-fail, mille rakendus varukoopia tegemisel kirjutas.',
        'restore_contents_unreadable' => 'Varukoopia avanes, kuid selles olev andmebaas on kahjustatud, seega seda ei taastatud ja midagi ei muudetud. Taasta varasemast varukoopiast.',
        'restore_could_not_read' => 'Varukoopia faili ei õnnestunud lugeda, seega taastamist ei tehtud ja midagi ei muudetud. Kontrolli, kas seadmes on vaba ruumi, ja proovi uuesti.',
        'restore_not_supported' => 'Taastamine töötab versioonis, mis hoiab oma andmeid ühes failis, ja see ei ole see, seega midagi ei muudetud. Serveri andmebaasi puhul kasuta selle andmebaasi enda taastamistööriistu.',
        'restore_failed' => 'Taastamist ei tehtud ja midagi ei muudetud. Proovi uuesti — kui see ikka ebaõnnestub, kirjutab rakenduse logi üles, mis selle peatas.',
    ],
];
