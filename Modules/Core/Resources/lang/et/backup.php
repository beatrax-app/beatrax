<?php

declare(strict_types=1);

return [
    'download' => [
        'no_download_route' => 'See telefon ei saa salvestada faili, mille rakendus talle annab, seega tehakse krüptitud varukoopia lauaarvuti rakenduses. Seo see seade, et need püsiksid sünkroonis.',
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

        'intro_html' => 'Asenda praegune andmebaas krüpteeritud varukoopiaga. Fail dekrüpteeritakse ja kontrollitakse enne mis tahes muudatust ning praegustest andmetest salvestatakse esmalt taastamiseelne hetktõmmis — aga see <strong class="text-slate-700 dark:text-slate-200">kirjutab kõik üle</strong>, seega on see samm kaitstud.',
        'restored' => 'Taastatud. Laadi rakendus uuesti, et taastatud andmeid näha.',
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
        'enter_passphrase' => 'Sisesta paroolifraas, millega varukoopia krüpteeriti.',
        'unreadable' => 'Üleslaaditud faili ei õnnestunud lugeda. Proovi uuesti.',
    ],
];
