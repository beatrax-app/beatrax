<?php

declare(strict_types=1);

return [
    'groups' => [
        'display' => 'Megjelenítés',
        'money' => 'Pénz',
        'insights' => 'Elemzések és riasztások',
        'security' => 'Biztonság és eszközök',
        'data' => 'Importok és adatok',
        'app' => 'Alkalmazás',
    ],

    'title' => 'Beállítások',
    'subtitle' => 'Beállítások arról, hogyan jelenjenek meg a pénzügyeid az alkalmazásban.',

    'appearance' => [
        'heading' => 'Megjelenés',
        'theme' => 'Téma',
        'theme_light' => 'Világos',
        'theme_dark' => 'Sötét',
        'theme_system' => 'Rendszer',
        'theme_help' => 'A Rendszer az operációs rendszered világos vagy sötét beállítását követi.',
    ],

    'language' => [
        'apply' => 'Alkalmaz',
        'heading' => 'Nyelv',
        'label' => 'Megjelenítés nyelve',

        'system' => 'Rendszer',
        'help' => 'Megváltoztatja a képernyőn megjelenő szavakat és az összegek írásmódját. A Rendszer a böngésződ vagy az operációs rendszered nyelvét követi, alapértelmezésben az angolt.',
    ],

    'country' => [
        'heading' => 'Ország',
        'label' => 'Az országod',
        'help' => 'Meghatározza, melyik ország adószabályait, hivatalait és banki díjait ismeri fel az alkalmazás. A nyelvet és az összegek írásmódját nem változtatja meg.',
        'choose' => 'Válassz országot…',
        'switch_note' => 'A váltás új kategóriákat ad hozzá — a meglévő címkék soha nem változnak.',

        'wording_note' => 'Az adókategóriák nevei a :country országban használt adóbevallásból származnak, ezért az alkalmazás minden nyelvén az adott ország szavaival maradnak.',

        'countries' => [
            'at' => 'Ausztria',
            'be' => 'Belgium',
            'bg' => 'Bulgária',
            'ca' => 'Kanada',
            'ch' => 'Svájc',
            'cy' => 'Ciprus',
            'cz' => 'Csehország',
            'de' => 'Németország',
            'dk' => 'Dánia',
            'ee' => 'Észtország',
            'es' => 'Spanyolország',
            'fi' => 'Finnország',
            'fr' => 'Franciaország',
            'gb' => 'Egyesült Királyság',
            'gr' => 'Görögország',
            'hr' => 'Horvátország',
            'hu' => 'Magyarország',
            'ie' => 'Írország',
            'is' => 'Izland',
            'it' => 'Olaszország',
            'lt' => 'Litvánia',
            'lu' => 'Luxemburg',
            'lv' => 'Lettország',
            'mt' => 'Málta',
            'nl' => 'Hollandia',
            'no' => 'Norvégia',
            'pl' => 'Lengyelország',
            'pt' => 'Portugália',
            'ro' => 'Románia',
            'se' => 'Svédország',
            'si' => 'Szlovénia',
            'sk' => 'Szlovákia',
            'us' => 'Amerikai Egyesült Államok',
        ],
    ],

    'currency_display' => [
        'heading' => 'Összeg megjelenítése',
        'label' => 'Alapértelmezett nézet a tranzakciólistán',
        'eur_only' => 'Elszámolt összeg',
        'original' => 'Eredeti összeg',
        'help' => 'A tranzakciólistán oldalanként továbbra is válthatsz.',
    ],

    'base_currency' => [
        'heading' => 'Jelentések alapdevizája',
        'label' => 'Jelentési pénznem',
        'help' => 'Minden összeg és összesítés erre a pénznemre vált át. Minden számla mellett továbbra is látszik a saját eredeti pénzneme.',
    ],

    'exchange_rates' => [
        'heading' => 'Árfolyamok',
        'fetch_online' => 'Aktuális árfolyamok letöltése online',
        'online_on' => 'Az árfolyamok naponta az ECB-től érkeznek. Csak devizapár-lekérdezés — személyes adat nélkül.',
        'last_updated' => 'Utoljára frissítve: :date.',
        'online_off' => 'A csomagolt árfolyamok vannak használatban. Semmilyen adat nem hagyja el ezt az eszközt.',
        'fetch_aria' => 'Aktuális árfolyamok letöltése online',
        'refreshing' => 'Frissítés…',
        'next_refresh' => 'Automatikus frissítés: naponta egyszer',
        'refresh_gave_up' => 'Az árfolyamokat nem sikerült frissíteni. Továbbra is az eszközön lévő árfolyamok érvényesek.',
        'refresh_now' => 'Frissítés most',
    ],

    'period' => [
        'heading' => 'Időszak',
        'label' => 'Az időszak kezdőnapja',
        'help' => '1-től 28-ig számozva. A legtöbben az 1-en hagyják (naptári hónap). Használd a 25-öt, ha a fizetésed 25-én érkezik, és onnantól számítod „a saját hónapodat”.',

        'move_confirm' => 'Ha az időszak a :day. napon kezdődik, minden borítékösszeg új helyre kerül, és összeadódik ott, ahol két hónap egybeolvad. A nap visszaállítása nem választja szét őket újra.',
        'move_cancel' => 'Mégse',
        'move_apply' => 'Alkalmaz',
    ],

    'recurring' => [
        'heading' => 'Ismétlődések felismerése',
        'window_label' => 'Felismerési ablak (hónap)',
        'window_help' => 'Hány hónapnyi előzményt vizsgáljon a rendszer, amikor a tranzakciókat ismétlődő mintákba csoportosítja.',
        'income_label' => 'Bevételi minimum (váltópénzben)',
        'income_help' => 'Az e küszöbérték alatti bevételek nem kerülnek automatikus csoportba. Váltópénzben tárolva — az :minor azt jelenti: :example. A küszöb kikapcsolásához állítsd 0-ra.',
    ],

    'drift' => [
        'heading' => 'Eltérésriasztások',
        'label' => 'Alapértelmezett eltérésriasztási küszöb',
        'help' => 'A riasztás akkor szólal meg, ha egy ismétlődő terhelés legutóbbi összege ennél nagyobb százalékkal tér el az előzőtől. A sorozatonkénti egyedi beállítás elsőbbséget élvez.',
        'options' => [
            '1' => '±1%',
            '2' => '±2%',
            '5' => '±5% (alapértelmezett)',
            '10' => '±10%',
            '25' => '±25%',
            '50' => '±50%',
        ],
    ],

    'save' => 'Beállítások mentése',
    'saved' => 'Mentve.',

    'anomaly_heading' => 'Anomáliafelismerés',
    'notifications_heading' => 'Értesítések',

    'forecasting' => [
        'heading' => 'Előrejelzés',
        'intro' => 'A Beatrax a számláid jelenlegi állapotából vetíti előre az egyenlegedet. Azoknál a számláknál, amelyekhez nincs kivonategyenleg (PayPal, régi CSV-importok), itt add meg a nyitó egyenleget, hogy az előrejelzés ismert pontról induljon.',
        'no_accounts' => 'Még nincs számla — importálj egy számlakivonatot, hogy legyen.',
    ],

    'auto_import' => [
        'heading' => 'Automatikus import',
        'label' => 'Automatikus import a ledobó mappából',

        'active_html' => 'A ledobó mappa aktív. A Beatrax 5 percenként átvizsgálja a <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> mappát új fájlokért.',
        'inactive_html' => 'Bekapcsolva a Beatrax 5 percenként átvizsgálja a <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> mappát <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> és <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> fájlokért, és ugyanazon az illesztési folyamaton importálja őket, mint a varázsló. A feldolgozott fájlok a <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> mappába kerülnek, így soha nem importálódnak kétszer.',
        'active_phone_html' => 'A ledobó mappa aktív. A Beatrax a háttérben vizsgálja át a <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> mappát új fájlokért. Hogy mikor fut le egy háttérvizsgálat, azt a telefonod dönti el — ez lehet néhány perc, de akár több óra is.',
        'inactive_phone_html' => 'Bekapcsolva a Beatrax a háttérben vizsgálja át a <code class="font-mono text-slate-700 dark:text-slate-300">storage/app/inbox-drop/:userId/</code> mappát <code class="font-mono text-slate-700 dark:text-slate-300">.eml</code> és <code class="font-mono text-slate-700 dark:text-slate-300">.mbox</code> fájlokért, és ugyanazon az illesztési folyamaton importálja őket, mint a varázsló. Hogy mikor fut le egy háttérvizsgálat, azt a telefonod dönti el — ez lehet néhány perc, de akár több óra is. A feldolgozott fájlok a <code class="font-mono text-slate-700 dark:text-slate-300">/processed/{YYYY-MM}/</code> mappába kerülnek, így soha nem importálódnak kétszer.',
    ],

    'aliases' => [
        'heading' => 'Álnevek',
        'intro' => 'Nézd át és szerkeszd a beszédes neveket, amelyeket a rejtélyes kivonatleírásokhoz tanítottál a Beatraxnak.',
        'manage' => 'Álnevek kezelése →',
    ],

    'tax_heading' => 'Adó',
    'data_backup_heading' => 'Adatok és biztonsági mentés',

    'about_updates' => [
        'heading' => 'A frissítésekről',
        'body' => 'A Beatrax telepítés után automatikusan frissíti magát. A legelső verzió telepítése után a további verziók alkalmazáson belüli sávban érkeznek — nem kell visszatérned a GitHubra. Ha egy későbbi frissítés mégsem alkalmazható, a legfrissebb telepítőt bármikor letöltheted kézzel a kiadások oldaláról.',
        'body_phone' => 'Itt a Beatrax nem frissíti magát. A mobilalkalmazás új verziói az App Store-on vagy a Google Play-en át érkeznek, ugyanúgy, mint a többi alkalmazásod. A kiadások oldala felsorolja, mi változott az egyesekben.',
        'open_releases' => 'Kiadások oldalának megnyitása →',
    ],

    'privacy' => [
        'heading' => 'Adatvédelmi tájékoztató',
        'body' => 'A Beatrax a saját eszközeiden tartja a pénzügyeidet. A tájékoztató elmondja, mit jelent ez, mit küldenek az opcionális online funkciók, és hogyan távolíthatod el az adataidat.',
        'open' => 'Adatvédelmi tájékoztató elolvasása →',
        'url_hint' => 'Ha a hivatkozás nem nyílik meg, keresd fel:',
    ],

    'first_run_tour' => [
        'heading' => 'Első indítás bemutatója',
        'body' => 'Indítsd újra a beállítási varázslót, ha újra végig szeretnél menni a bevezető folyamaton.',
        'run_again' => 'Beállítási varázsló újbóli futtatása',
    ],

    'developer' => [
        'heading' => 'Fejlesztő',
        'label' => 'Beépített fejlesztői konzol',
        'help' => 'A fejlesztői konzol megjelenítése a /dev címen. Minden bejelentkezéskor visszaállítja a Haladó kapcsolót.',
        'aria' => 'Fejlesztői mód',
    ],

    'errors' => [
        'period_move_failed' => 'A költségvetési hónapot nem sikerült áthelyezni, ezért ott maradt, ahol volt.',
        'currency_required' => 'Válassz pénznemet.',
        'window_months' => 'Válassz 2 és 60 hónap között.',
        'threshold' => 'Válassz küszöbértéket: 1%, 2%, 5%, 10%, 25% vagy 50%.',
        'amount' => 'Adj meg egy összeget :zero-tól felfelé.',
        'period_day' => 'Válassz egy napot 1 és 28 között.',
        'currency_view' => 'Válassz az elérhető lehetőségek közül.',
    ],
];
