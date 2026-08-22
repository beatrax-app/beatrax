<?php

declare(strict_types=1);

return [
    'page_title' => 'Import előnézete',
    'heading' => 'Import előnézete',
    'discard' => 'Import elvetése',
    'confirm' => 'Import megerősítése',
    'subtitle' => 'Nézd át a beolvasott sorokat. A megerősítésig semmi nem kerül a főkönyvedbe.',

    'expired_html' => 'Az előnézet lejárt. <a href="/imports/new" class="underline">Töltsd fel újra a fájlt</a>, és próbáld meg ismét.',

    'save_name' => 'Név mentése',
    'account_name_label' => 'Számla neve',
    'account_placeholder' => 'pl. Fő megtakarítási számla',
    'rename_aria' => 'Ennek a partnernek az átnevezése',

    'unknown_iban_prefix' => 'Ismeretlen IBAN-t találtunk:',
    'unknown_iban_suffix' => 'Nevezd el ezt a számlát.',

    'ics' => [
        'heading' => 'Nevezd el az ICS-kártyaszámládat.',
        'help' => 'Most először importálsz ICS-adatokat. Adj nevet ennek a kártyának, hogy egységesen jelenjen meg az alkalmazásban.',
        'placeholder' => 'pl. ICS-kártya',
    ],

    'paypal' => [
        'heading' => 'Nevezd el a PayPal-számládat.',
        'help' => 'Most először importálsz PayPal-adatokat. Adj nevet ennek a tárcának, hogy egységesen jelenjen meg az alkalmazásban.',
        'placeholder' => 'pl. PayPal',
    ],

    'col_date' => 'Dátum',
    'col_funding_source' => 'Finanszírozási forrás',
    'col_counterparty' => 'Partner',
    'col_amount' => 'Összeg',
    'col_status' => 'Állapot',

    'status' => [
        'new' => 'Új',
        'new_title' => 'Bekerül a főkönyvedbe.',
        'duplicate' => 'Duplikátum',
        'duplicate_title' => 'Már importálva — kimarad.',
        'enriched' => 'Kiegészítve',
        'enriched_title' => 'A meglévő sor erősebb forráshivatkozással frissül.',
        'error' => 'Hiba',
    ],

    'chain' => [
        'heading' => 'Láncok feloldása…',
        'pending' => 'Várólistán. A láncfeloldó hamarosan elindul.',
        'running' => 'Finanszírozási láncok összekapcsolása és a kivonatelszámolások felbontása.',
        'failed_prefix' => 'A láncfeloldás sikertelen:',
        'failed_detail' => 'a részletek a feladatnaplóban vannak',
        'open_horizon' => 'Horizon megnyitása',
        'failed_suffix' => 'az újrapróbáláshoz vagy a megtekintéshez.',
    ],

    'errors' => [
        'app_locked' => 'Oldja fel az alkalmazást az importáláshoz: a titkosítási kulcsok zárolt állapotban nem használhatók.',
        'file_unreadable' => 'Ezt a fájlt nem sikerült beolvasni.',
        'iban_not_in_preview' => 'Ez az IBAN nem része az aktuális előnézetnek.',
        'row_unreadable' => 'Ezt a sort nem sikerült beolvasni.',
        'unknown_account' => 'Ez a sor olyan számlához tartozik, amelynek még nem adtál nevet.',
    ],

    'failed' => [
        'heading' => 'Ezt a fájlt nem sikerült beolvasni',
        'no_rows' => 'Ebben a fájlban nem található tranzakció, így nincs mit importálni.',
        'nothing_read' => 'Semmit nem sikerült tranzakcióként beolvasni ebből a fájlból, így nincs mit importálni.',
        'every_row' => 'A fájl egyetlen sorát sem sikerült beolvasni, így nincs mit importálni. Minden sor az okával együtt lent szerepel.',
        'likely_cause' => 'Általában a fejlécsor nem egyezik a választott forrással. Ellenőrizd a bankot és a formátumot a feltöltési képernyőn, vagy töltsd le újra a kivonatot a bankodtól.',
        'truncated_heading' => 'A fájlnak csak egy része volt beolvasható',
        'truncated' => 'A beolvasás a fájl közepén megállt. Az azután következőket nem olvastuk be, és nem is importáljuk.',
        'some_rows' => 'Néhány sort nem sikerült beolvasni. Lent meg vannak jelölve, és kimaradnak; a megerősítéssel a többi importálásra kerül.',
        'detail_label' => 'Amit az elemző jelentett:',
        'rows_read_label' => 'Beolvasott sorok',
        'rows_skipped_label' => 'Kihagyott sorok',
    ],
];
