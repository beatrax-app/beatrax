<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Teave: :subject',
        'close' => 'Sulge',
    ],

    'page_title' => 'Kus mu andmed on?',
    // i18n-review: et · intro — "the devices you pair for sync" became the
    // relative "seadmed, mille sünkroonimiseks seod". A native should say
    // whether that reads inside an em-dash list, or wants a clause of its own.
    'intro' => 'Beatrax hoiab kõike selles seadmes. Beatraxi serverit ega pilvekontot ei ole. Omal algatusel läheb välja üksainus päring — kontroll, kas on olemas uus versioon, ja selle saad välja lülitada. Kõik muu ootab sind: postkast, pank Enable Bankingu kaudu, igapäevane vahetuskursside päring, seadmed, mille sünkroonimiseks seod, sinu seadistatud relee ja iga link, millele klõpsad. Igaüks neist ütleb seda ekraanil, kus sa selle sisse lülitad.',

    'lives_here' => 'Sinu andmed asuvad siin',
    'copy' => 'Kopeeri',
    'copied' => 'Kopeeritud',

    'location' => [
        'database' => 'Andmebaas:',
        'artifacts_imports' => 'Imporditud väljavõtted:',
        'artifacts_mail' => 'Skannitud kirjad:',
        'artifacts_drop' => 'Jälgitav kaust:',
        'backups' => 'Varukoopiad:',
        'secrets' => 'Ühenduste mandaadid:',
        'logs' => 'Logid:',
    ],

    'copy_aria' => [
        'database' => 'Kopeeri andmebaasi asukoht lõikelauale',
        'artifacts_imports' => 'Kopeeri imporditud väljavõtete asukoht lõikelauale',
        'artifacts_mail' => 'Kopeeri skannitud kirjade asukoht lõikelauale',
        'artifacts_drop' => 'Kopeeri jälgitava kausta asukoht lõikelauale',
        'backups' => 'Kopeeri varukoopiate asukoht lõikelauale',
        'secrets' => 'Kopeeri ühenduste mandaatide asukoht lõikelauale',
        'logs' => 'Kopeeri logide asukoht lõikelauale',
    ],

    'artifacts_heading' => 'Sinu lähtedokumendid ei ole varukoopia sees',
    'artifacts_body' => 'Varukoopia sisaldab andmebaasi ja ei midagi muud. Väljavõtted, mille importisid, kirjad, mille skanner tõi, ja tšekid, mille jälgitavasse kausta panid, jäävad sinna, kus nad on — kolme ülalloetletud kausta. Varukoopia turvalisse kohta viimine neid kaasa ei kopeeri, seega täielik arhiiv tähendab ka nende kaustade kaasavõtmist — või allolevat käsku Ekspordi kõik, mis pakib need koos varukoopiaga kokku.',

    'export_heading' => 'Ekspordi kõik',
    'export_body' => 'Üks arhiiv, milles on sinu andmebaasi krüpteeritud koopia ja iga lähtedokument, mille oled Beatraxile andnud. Paki see lahti kus tahad ja dokumendid on seal sees täpselt sellisena, nagu nad alati olid, kaustades, kust nad tulid.',
    'export_passphrase_label' => 'Andmebaasi paroolifraas',
    'export_confirm_label' => 'Korda paroolifraasi',
    'export_passphrase_hint' => 'Arhiivis olev andmebaas krüpteeritakse selle paroolifraasiga ja ilma selleta seda avada ei saa, seega vali midagi, mis sul ka hiljem alles on. Lähtedokumendid lähevad sisse muutmata kujul, nii et hoia arhiivi kohas, mida usaldad.',
    'export_cta' => 'Ekspordi kõik ZIP-failina',
    'export_working' => 'Arhiivi koostatakse…',

    'delete_heading' => 'Andmete kustutamine',
    'delete_intro' => 'Sinu andmed on failid selles seadmes, nii et nende kustutamine tähendab nende failide kustutamist. Siin ei ole nuppu, mis seda sinu eest teeks, ja see on meelega: sinu ajalugu hoiab tegelikult failisüsteem ning nupp, mis tühjendaks paar tabelit ja jätaks failid alles, oleks halvem kui mitte midagi.',
    'delete_uninstall' => 'Beatraxi eemaldamine ei kustuta sinu andmeid. See on tahtlik — kogemata eemaldamine ei tohi hävitada aastate ajalugu — nii et kõik allpool olev jääb sellesse seadmesse, kuni sa selle ise ära kustutad.',
    'delete_list_intro' => 'Iga jälje kaotamiseks kustuta kõik need:',
    'delete_journal_note' => 'Andmebaasi kõrval on kaks žurnaalifaili, :wal ja :shm. Sinu kõige värskemad muudatused on neis seni, kuni need andmebaasi kantakse, seega kustuta kõik kolm koos.',
    'no_telemetry' => 'Telemeetriat, millest loobuda, ei ole, ega ka kaugkontot, mida sulgeda.',

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Ajavahemik, mille kohta mõõdetakse su eelarveid, töölauda ja iga „selle perioodi“ arvu. „:label“ otsustab, kust see algab — pane see palgapäevale järgnevaks päevaks ja periood hoiab raha, mis selle katteks maksti. Selle päeva nihutamine paigutab ümber iga ümbrikusumma, mille oled juba jaotanud, ja kus kaks vana perioodi voltuvad ühte uude, liidetakse nende summad kokku; päeva tagasi nihutamine neid uuesti lahku ei võta.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Kõik, mille kohta Beatrax teab, et see on sinu oma, miinus kõik, mida sa võlgned: iga sinu imporditud või ühendatud konto jääk, kusjuures kaardi- ja laenujäägid loetakse sinu kahjuks. Täielikum kui see, mille oled talle andnud, see ei ole — konto, mida Beatrax pole kunagi näinud, ei ole selles arvus. Teise valuuta jäägid arvestatakse ümber arvu all näidatud kursiga ja see, mida hinnata ei õnnestunud, nimetatakse seal, mitte ei jäeta vaikselt välja.',
];
