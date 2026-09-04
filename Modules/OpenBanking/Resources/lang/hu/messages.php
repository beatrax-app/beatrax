<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Beállítások',
        'heading' => 'Nyílt bankolás',
        'subtitle' => 'Automatikusan lekéri a tranzakciókat az ASN vagy az SNS banktól az Enable Banking, egy külső PSD2-aggregátor segítségével. Alapértelmezés szerint kikapcsolva.',
        'toggle_label' => 'Nyílt bankolás bekapcsolása',
        'toggle_connected' => 'Csatlakoztatva ide: :bank, az Enable Bankingen keresztül.',
        'toggle_off_help' => 'Alapértelmezés szerint kikapcsolva. Egyszeri tudomásulvételt és vezetett beállítást igényel.',
        'credentials_unreadable' => 'A nyílt bankolás ezen az eszközön tárolt hitelesítő adatai nem olvashatók, ezért a Beatrax nem éri el a bankodat.',
        'credentials_unreadable_next' => 'Végezd el újra a vezetett beállítást, hogy lecseréld őket. A már importált tranzakciókat ez nem érinti.',
        'reconfirm_body' => 'A tudomásulvételed lejárt, mielőtt befejezhettük volna a csatlakoztatást. Erősítsd meg újra a nyílt bankolás bekapcsolásának befejezéséhez.',
        'reconfirm_button' => 'Újbóli megerősítés a befejezéshez',
    ],

    'status_row' => [
        'heading' => 'Nyílt bankolás',
        'manage' => 'Nyílt bankolás kezelése',
        'not_connected' => 'Nincs csatlakoztatott bank. Csatlakoztass egyet a tranzakciók automatikus importálásához.',
        'expired' => 'A hozzájárulás lejárt — újracsatlakozás szükséges.',
        'revoked' => 'A bankod lezárta a kapcsolatot — csatlakozz újra.',
        'connected' => 'Csatlakoztatva ide: :bank, az Enable Bankingen keresztül. Utolsó szinkronizálás: :when.',
        'never' => 'soha',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregátor',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Hozzájárulás állapota',
        'pill_expired' => 'Lejárt — csatlakozz újra',
        'pill_expiring' => 'Hamarosan lejár',
        'pill_connected' => 'Csatlakoztatva',
        'pill_revoked' => 'A bankod zárta le — csatlakozz újra',
        'whats_fetched_label' => 'Mit kérünk le',
        'whats_fetched' => 'Könyvelt tranzakciók és egyenlegek, az elmúlt 90 nap',
        'last_successful_sync_label' => 'Utolsó sikeres szinkronizálás',
        'never' => 'Soha',
        'last_attempt_label' => 'Utolsó kísérlet',
        'last_attempt_failed' => ':when — sikertelen (:reason)',
        'reason_consent_expired' => 'a hozzájárulás lejárt',
        'reason_error' => 'hiba',
        'reason_truncated' => 'korán leállt',
        'reason_nothing_imported' => 'semmit sem sikerült rögzíteni',
        'reason_consent_revoked' => 'a bankod zárta le',
        'disconnect_button' => 'Leválasztás',
    ],

    'consent_banner' => [
        'heading' => 'A hozzájárulás lejárt — csatlakozz újra',
        'heading_revoked' => 'A bankod lezárta a kapcsolatot',
        'body' => 'Az utolsó sikeres szinkronizálás ekkor volt: :when. Csatlakozz újra az automatikus szinkronizálás folytatásához.',
        'body_revoked' => 'A bankod vagy az Enable Banking visszavonta a hozzáférést, ezért a szinkronizálás leállt. A legutóbbi sikeres szinkronizálás ekkor volt: :when. Csatlakozz újra, és folytatódik.',
        'never' => 'soha',
        'reconnect' => 'Újracsatlakozás',
    ],

    'sync' => [
        'review_import' => 'Import áttekintése',
        'reconnect_first' => 'Előbb csatlakozz újra',
        'auto_caption' => 'Naponta egyszer automatikusan szinkronizál.',
        'sync_now' => 'Szinkronizálás most',

        'consent_expired' => 'A hozzájárulás lejárt — csatlakozz újra.',
        'unavailable' => 'Az Enable Banking átmenetileg nem érhető el. Próbáld újra hamarosan.',
        'new_found' => ':count új tranzakció található.|:count új tranzakció található.',
        'none' => 'Nincs új tranzakció.',
        'none_importable' => 'A bankod küldött tranzakciókat, de egyiket sem sikerült rögzíteni. Nyisd meg az import áttekintését, hogy lásd miért.',
        'in_progress' => 'Már fut egy szinkronizálás. Próbálja meg újra egy pillanat múlva.',
        'truncated' => 'A bankodnál több tranzakció volt, mint amennyit egy szinkronizálás le tud kérni, ezért ez a futás korán leállt. Semmi sem lett szinkronizáltként rögzítve — a következő szinkronizálás ugyanonnan indul.',
    ],

    'disconnect' => [
        'heading' => 'Leválasztod a nyílt bankolást?',
        'body' => 'Ezzel törlődnek a tárolt Enable Banking-hitelesítő adataid és a hozzájárulásod. Az automatikus szinkronizálás azonnal leáll. A Beatraxba már importált tranzakciókat ez nem érinti.',
        'confirm' => 'Leválasztás',
        'cancel' => 'Maradjon csatlakoztatva',
    ],

    'ics' => [
        'section_label' => 'Fájlimport — nem tárolunk hitelesítő adatokat',
        'heading' => 'ICS hitelkártya-kivonat',
        'step_login' => 'Jelentkezz be',
        'step_download' => 'Töltsd le a kivonatot',
        'pdf_statement' => 'PDF-kivonat',
        'step_drop' => 'Húzd ide alá',
        'drop_zone_label' => 'Húzd ide a kivonatfájlodat',
        'drop_zone_hint' => 'vagy tallózz egy fájlt',
        'browse_aria' => 'ICS-kivonatfájl tallózása',
        'import_button' => 'Kivonat importálása',
        'validation' => [
            'required' => 'Húzd ide a Mijn ICS-ből letöltött ICS-kivonatot.',
            'max' => 'Ez a fájl túl nagy. Az ICS PDF-kivonatok általában 1 MB alatt maradnak.',
            'extensions' => 'Ez nem PDF. A Mijn ICS csak PDF-kivonatokat exportál.',
        ],
        'could_not_read' => 'A(z) :filename nem olvasható. A teljes hiba a /dev/logs oldalon található.',
    ],

    'warning' => [
        'heading' => 'Mielőtt külső szolgáltatót kapcsolsz be',
        'body' => 'A nyílt bankolás bekapcsolásával a banki bejelentkezési hozzájárulásod, majd a tranzakció- és egyenlegadataid közvetlenül erről az eszközről jutnak el az Enable Bankinghez és a bankodhoz. A Beatrax nem üzemeltet olyan szervert, amely látná ezeket az adatokat — az Enable Banking és a bankod viszont látja. Ez eltér a Beatrax összes többi importálási módjától, amelyek soha nem küldenek adatot sehová.',
        'acknowledge' => 'Megértettem, hogy a tranzakciós adataim megosztásra kerülnek az Enable Bankinggel és a bankommal.',
        'confirm' => 'Nyílt bankolás bekapcsolása',
        'cancel' => 'Mégse',
    ],

    'wizard' => [
        'heading' => 'Csatlakoztasd a bankod',
        'intro' => 'A Beatrax a saját Enable Banking-alkalmazásodat használja, így a hitelesítő adataid soha nem kerülnek közös kiszolgálóra. Ez bankonként egyszeri beállítás.',

        'step1_title' => 'Hozd létre a helyi kulcspárt',
        'step1_body' => 'A Beatrax RSA-kulcspárt generál ezen az eszközön. A privát kulcs soha nem hagyja el.',
        'generate_keypair' => 'Kulcspár generálása',
        'public_key_label' => 'Nyilvános kulcs',
        'copy_public_key' => 'Nyilvános kulcs másolása',
        'copied' => 'Másolva',
        'redirect_uri_label' => 'Átirányítási URI',
        'copy_redirect_uri' => 'Átirányítási URI másolása',

        'step2_title' => 'Regisztráld az alkalmazást az Enable Bankingben',
        'step2_body' => 'Nyisd meg az Enable Banking fejlesztői portálját, hozz létre egy alkalmazást, és illeszd be az 1. lépésben kapott nyilvános kulcsot és átirányítási URI-t.',
        'open_portal' => 'Enable Banking portál megnyitása ↗',

        'step3_title' => 'Illeszd be az alkalmazásazonosítót',
        'application_id_label' => 'Alkalmazásazonosító',
        'step3_help' => 'Ezt az adatbázison kívüli helyi fájlban, szigorú jogosultságokkal tároljuk, és soha nem hagyja el ezt az eszközt.',

        'step4_title' => 'Válaszd ki a bankod',
        'via_enable_banking' => 'az Enable Bankingen keresztül',
        'other_institution' => 'Más intézmény',
        'institution_id_placeholder' => 'Intézményazonosító',

        'step5_title' => 'Fejezd be a hozzájárulást a böngésződben',
        'step5_body' => 'Kattints alább a bankod bejelentkezési és hozzájárulási képernyőjének megnyitásához. Végezd el a bejelentkezést és az esetleges kétlépcsős azonosítást, utána automatikusan visszakerülsz ide, hogy befejezd a nyílt bankolás bekapcsolását.',
        // i18n-review: hu · step5_body_touch — the same line for a touch
        // screen; check the verb governs this case.
        'step5_body_touch' => 'Koppints alább a bankod bejelentkezési és hozzájárulási képernyőjének megnyitásához. Végezd el a bejelentkezést és az esetleges kétlépcsős azonosítást, utána automatikusan visszakerülsz ide, hogy befejezd a nyílt bankolás bekapcsolását.',

        'cancel' => 'Mégse',
        'continue' => 'Folytatás →',
        'continue_to_bank' => 'Tovább ide: :bank →',
        'your_bank' => 'a bankod',

        'errors' => [
            'save_keypair_failed' => 'A kulcspárt nem sikerült lemezre menteni — ellenőrizd a titkokat tároló könyvtár jogosultságait, és próbáld újra.',
            'generate_failed' => 'Nem sikerült kulcspárt generálni ezen az eszközön — ellenőrizd az OpenSSL-beállításaidat.',
            'export_failed' => 'A generált kulcspárt nem sikerült exportálni.',
            'read_public_failed' => 'A generált nyilvános kulcs nem olvasható.',
            'generate_first' => 'Generálj kulcspárt a folytatás előtt.',
            'paste_application_id' => 'Illeszd be az Enable Banking portálról származó alkalmazásazonosítót a folytatás előtt.',
            'save_application_id_failed' => 'Az alkalmazásazonosítót nem sikerült lemezre menteni — ellenőrizd a titkokat tároló könyvtár jogosultságait, és próbáld újra.',
            'choose_bank' => 'Válassz bankot a folytatás előtt.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Előbb fejezd be a nyílt bankolás beállítási varázslóját.',
        'no_bank_chosen' => 'Válassz bankot a csatlakoztatás előtt.',
        'no_consent_url' => 'Az Enable Banking nem adott vissza hozzájárulási URL-t.',
        'unparseable_consent_url' => 'Az Enable Banking értelmezhetetlen hozzájárulási URL-t adott vissza.',
        'non_public_consent_host' => 'Az Enable Banking nem nyilvános hozzájárulási kiszolgálót adott vissza.',
        'unsafe_consent_url' => 'Az Enable Banking nem biztonságos hozzájárulási URL-t adott vissza.',
        'no_authorization_code' => 'Az Enable Banking visszahívása nem adott vissza engedélyezési kódot.',
        'no_session_id' => 'Az Enable Banking nem adott vissza munkamenet-azonosítót.',
        'oauth_state_mismatch' => 'Ez a kapcsolódási hivatkozás lejárt, vagy már felhasználták. Kezdje elölről a bank összekapcsolását.',
    ],
];
