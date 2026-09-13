<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => 'Tudnivalók: :subject',
        'close' => 'Bezárás',
    ],

    'page_title' => 'Hol vannak az adataim?',
    'intro' => 'A Beatrax mindent ezen az eszközön tárol. Nincs Beatrax-kiszolgáló és nincs felhőfiók. Egyetlen hívás indul magától — az új verzió ellenőrzése, amelyet ki is kapcsolhatsz. Minden más rád vár: egy postafiók, egy bank az Enable Bankingen keresztül, a napi árfolyam-lekérdezés, az eszközök, amelyeket szinkronizálásra párosítasz, az általad beállított relé, és minden hivatkozás, amelyre rákattintasz. Mindegyik elmondja ezt azon a képernyőn, ahol bekapcsolod.',

    'lives_here' => 'Az adataid itt vannak',
    'copy' => 'Másolás',
    'copied' => 'Másolva',

    'location' => [
        'database' => 'Adatbázis:',
        'artifacts_imports' => 'Importált kivonatok:',
        'artifacts_mail' => 'Beolvasott levelek:',
        'artifacts_drop' => 'Figyelt mappa:',
        'backups' => 'Biztonsági mentések:',
        'secrets' => 'Kapcsolatok hitelesítő adatai:',
        'logs' => 'Naplók:',
    ],

    'copy_aria' => [
        'database' => 'Az adatbázis elérési útjának másolása a vágólapra',
        'artifacts_imports' => 'Az importált kivonatok elérési útjának másolása a vágólapra',
        'artifacts_mail' => 'A beolvasott levelek elérési útjának másolása a vágólapra',
        'artifacts_drop' => 'A figyelt mappa elérési útjának másolása a vágólapra',
        'backups' => 'A biztonsági mentések elérési útjának másolása a vágólapra',
        'secrets' => 'A kapcsolatok hitelesítő adatai elérési útjának másolása a vágólapra',
        'logs' => 'A naplók elérési útjának másolása a vágólapra',
    ],

    'artifacts_heading' => 'A forrásdokumentumaid nincsenek benne a biztonsági mentésben',
    'artifacts_body' => 'A biztonsági mentés az adatbázist tartalmazza, mást semmit. Az általad importált kivonatok, a beolvasó által behúzott levelek és a figyelt mappába ejtett bizonylatok ott maradnak, ahol vannak: a fenti három mappában. Ha a mentést biztonságos helyre teszed, ezek nem másolódnak vele, így a teljes archívumhoz ezeket a mappákat is vinned kell — vagy használd lent a Minden exportálása lehetőséget, amely a mentéssel együtt becsomagolja őket.',

    'export_heading' => 'Minden exportálása',
    'export_body' => 'Egyetlen archívum, benne az adatbázisod titkosított másolata és minden forrásdokumentum, amit a Beatraxnak adtál. Csomagold ki bárhol, és a dokumentumaid úgy vannak benne, ahogy mindig is voltak, abban a mappában, ahonnan jöttek.',
    'export_passphrase_label' => 'Jelmondat az adatbázishoz',
    'export_confirm_label' => 'Ismételd meg a jelmondatot',
    'export_passphrase_hint' => 'Az archívumban lévő adatbázist ez a jelmondat titkosítja, és nélküle sehogy sem nyitható meg, ezért olyat válassz, ami később is megvan. A forrásdokumentumok változatlanul kerülnek bele, ezért az archívumot olyan helyen tartsd, amiben megbízol.',
    'export_cta' => 'Minden exportálása ZIP-be',
    'export_working' => 'Az archívum készül…',

    'delete_heading' => 'Az adataid törlése',
    'delete_intro' => 'Az adataid fájlok ezen az eszközön, így törölni őket annyit tesz, hogy törlöd ezeket a fájlokat. Nincs itt gomb, ami ezt elvégezné helyetted, és ez szándékos: az előzményeidet valójában a fájlrendszer őrzi, egy olyan vezérlő pedig, ami néhány táblát kiürít, a fájlokat viszont a helyükön hagyja, rosszabb lenne a semminél.',
    'delete_uninstall' => 'A Beatrax eltávolítása nem törli az adataidat. Ez szándékos — egy véletlen eltávolítás nem semmisíthet meg évekre visszamenő előzményeket —, ezért minden alábbi ezen az eszközön marad, amíg te magad el nem távolítod.',
    'delete_list_intro' => 'Ha minden nyomot el akarsz tüntetni, töröld mindegyiket:',
    'delete_journal_note' => 'Az adatbázis mellett két naplófájl található, a :wal és a :shm. A legfrissebb módosításaid ezekben vannak, amíg be nem kerülnek az adatbázisba, ezért mind a hármat együtt töröld.',
    'no_telemetry' => 'Nincs telemetria, amiről le kellene mondanod, és nincs távoli fiók, amit be kellene zárnod.',

    /** @link ../../../../../.docs/features/budgets/moving-the-budget-month.md#the-mapping-keep-the-distance-from-the-month-the-reader-is-in */
    'period' => 'Az az időszakasz, amelyre a költségvetéseid, az áttekintőd és minden „ebben az időszakban” szereplő szám vonatkozik. A „:label” dönti el, hol kezdődik — állítsd a fizetésed utáni napra, és az időszak azt a pénzt tartalmazza, amit a fedezetére kaptál. Ennek a napnak az elmozdítása újrarendezi minden borítékba már kiosztott összegedet, és ahol két régi időszak egy újba hajlik, az összegeik összeadódnak; a napot visszaállítva nem válnak szét újra.',

    /** @link ../../../../../.docs/features/position/architecture.md#composition-never-a-raw-select */
    'net_worth' => 'Minden, amiről a Beatrax tudja, hogy a tiéd, mínusz minden, amivel tartozol: minden importált vagy összekapcsolt számla egyenlege, ahol a kártya- és hitelegyenlegek ellened számítanak. Nem teljesebb annál, amit megadtál neki — egy számla, amit a Beatrax sosem látott, nincs benne ebben a számban. A más pénznemben álló egyenlegek a szám alatt látható árfolyamon számítódnak át, és amit nem tudott beárazni, ott néven van nevezve ahelyett, hogy csendben kimaradna.',
];
