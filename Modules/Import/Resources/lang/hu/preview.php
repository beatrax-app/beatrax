<?php

declare(strict_types=1);

return [
    'page_title' => 'Import előnézete',
    'heading' => 'Import előnézete',
    'discard' => 'Import elvetése',
    'confirm' => 'Import megerősítése',
    'subtitle' => 'Nézd át a beolvasott sorokat. A megerősítésig semmi nem kerül a főkönyvedbe.',

    'already_imported' => 'Ezt a fájlt már importálta.',

    'already_imported_link' => 'Importálás eredményének megtekintése',

    'expired_html' => 'Az előnézet lejárt. <a href="/imports/new" class="underline">Töltsd fel újra a fájlt</a>, és próbáld meg ismét.',
    'unreadable_html' => 'Az előnézet nem olvasható. <a href="/imports/new" class="underline">Töltsd fel újra a fájlt</a>, és próbáld meg ismét.',

    'save_name' => 'Név mentése',
    'account_name_label' => 'Számla neve',
    'account_placeholder' => 'pl. Fő megtakarítási számla',
    'rename_aria' => 'Ennek a partnernek az átnevezése',

    'unknown_iban_prefix' => 'Ismeretlen IBAN-t találtunk:',

    'unknown_account_prefix' => 'Ismeretlen számlát találtunk:',
    'unknown_iban_suffix' => 'Nevezd el ezt a számlát.',

    'ics' => [
        'name' => 'ICS-kártya',
        'heading' => 'Nevezd el az ICS-kártyaszámládat.',
        'help' => 'Most először importálsz ICS-adatokat. Adj nevet ennek a kártyának, hogy egységesen jelenjen meg az alkalmazásban.',
        'placeholder' => 'pl. ICS-kártya',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Nevezd el a PayPal-számládat.',
        'help' => 'Most először importálsz PayPal-adatokat. Adj nevet ennek a tárcának, hogy egységesen jelenjen meg az alkalmazásban.',
        'placeholder' => 'pl. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Nevezd el a Google Play-fiókodat.',
        'help' => 'Most először importálsz Google Play-nyugtát. Adj nevet ennek a fióknak, hogy egységesen jelenjen meg az alkalmazásban.',
        'placeholder' => 'pl. Google Play',
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

    'rows_shown' => 'Megjelenített sorok: :shown / :total',

    'show_more' => 'Több sor megjelenítése',

    'errors' => [
        'app_locked' => 'Oldja fel az alkalmazást az importáláshoz: a titkosítási kulcsok zárolt állapotban nem használhatók.',
        'archive_holds_one_message' => 'Ez a fájl egyetlen e-mail-üzenet, nem postafiók-archívum, így archívumként olvasva nincs benne semmi. Töltsd fel újra E-mail-üzenet formátummal.',
        'email_file_is_an_archive' => 'Ez a fájl postafiók-archívum: egynél több üzenetet tartalmaz, és egyetlen üzenetként olvasva csak az elsőt venné ki belőle. Töltsd fel újra Postafiók-archívum formátummal.',
        'file_stopped_short' => 'A fejléc egyezett, tehát a formátum jó. Az olvasás a fájl vége előtt megállt. Ezt egyetlen olvashatatlan sor is okozza, és az is, ha a fájl túl nagy ehhez az eszközhöz. Próbálj rövidebb időszakot.',
        'file_unreadable' => 'Ezt a fájlt nem sikerült beolvasni.',
        'file_unreadable_detail' => 'Az alkalmazás nem tudta beolvasni ezt a fájlt (:code). A teljes részletek az alkalmazásnaplóban vannak; hibabejelentéskor adja meg ezt a kódot.',
        'iban_not_in_preview' => 'Ez az IBAN nem része az aktuális előnézetnek.',
        'message_unreadable' => 'Ezt az üzenetet nem sikerült beolvasni, ezért kimaradt.',
        'not_an_email_file' => 'Ez a fájl sem e-mail-üzenet, sem postafiók-archívum, így nincs benne mit bizonylatként kiolvasni. Válaszd azt az importtípust és formátumot, amely illik a fájlodhoz.',
        'pdf_has_no_text_layer' => 'Ez a PDF nem tartalmaz szöveget — egy kivonat beolvasott képe vagy fényképe, így nincs benne mit kiolvasni. Töltsd le magát a kivonatot a bankodtól, vagy használj CSV-exportot.',
        'pdf_password_protected' => 'Ez a PDF jelszóval védett, így egyetlen olvasó sem tudja megnyitni. Mentsd el a PDF-nézegetődből védelem nélküli másolatként, és azt importáld.',
        'pdf_reader_unavailable' => 'Az alkalmazás ezen változatában nincs semmilyen PDF-olvasó, így PDF kivonatot itt nem lehet megnyitni. Importáld ezt a fájlt egy másik eszközön, vagy használj CSV-exportot a bankodtól.',
        'row_belongs_to_another_statement' => 'Ez a sor egy másik kivonatfájlban lévő tranzakcióhoz tartozik. Importálja azt a kivonatot is — a kettőt együtt olvassuk be.',
        'row_unreadable' => 'Ezt a sort nem sikerült beolvasni.',
        'row_unreadable_detail' => 'Az alkalmazás nem tudta beolvasni ezt a sort (:code). A teljes részletek az alkalmazásnaplóban vannak; hibabejelentéskor adja meg ezt a kódot.',
        'unknown_account' => 'Ez a sor olyan számlához tartozik, amelynek még nem adtál nevet.',
    ],

    'refused' => [
        'accounts_to_name' => 'Ez a fájl arra vár, hogy nevet adj a számlának, amelyhez a sorai tartoznak.',
        'file_did_not_read_in_full' => 'Ezt a fájlt nem sikerült a végéig beolvasni.',
        'nothing_importable' => 'Ebből a fájlból semmi sem importálható.',
        'preview_expired' => 'Ennek a fájlnak az előnézete túl régi ahhoz, hogy most mentsük. Töltsd fel újra.',
    ],

    'receipts' => [
        'heading' => 'Ezt a fájlt e-mailként olvastuk be',
        'saved' => 'Ami benne volt, lent látható, és minden üzenet mentve van.',
        'none_imported' => 'Ezekből egy sem lett tranzakció, így a főkönyvedbe nem került semmi.',
        'shown' => 'Megjelenített üzenetek: :shown / :total',
        'no_subject' => 'Nincs tárgy',

        'state' => [
            'read' => 'Fizetésként beolvasva — erősítsd meg ezt az importot, hogy bekerüljön a főkönyvedbe.',
            'not_a_payment' => 'Nem fizetés. Ez az üzenet bejelent valamit, nem pedig fizetést igazol.',
            'unreadable' => 'Mentve. Az alkalmazás olvassa ennek a feladónak a nyugtáit, de ebben az üzenetben nem talált összeget, kereskedőt és hivatkozást.',
            'unknown_sender' => 'Mentve. Az alkalmazás nem olvassa ennek a feladónak a nyugtáit, ezért semmit nem vett át az üzenetből.',
        ],
    ],

    'failed' => [
        'heading' => 'Ezt a fájlt nem sikerült beolvasni',
        'no_rows' => 'Ebben a fájlban nem található tranzakció, így nincs mit importálni.',
        'nothing_read' => 'Semmit nem sikerült tranzakcióként beolvasni ebből a fájlból, így nincs mit importálni.',
        'every_row' => 'A fájl egyetlen sorát sem sikerült beolvasni, így nincs mit importálni. Minden sor az okával együtt lent szerepel.',
        'likely_cause' => 'Általában a fejlécsor nem egyezik a választott forrással. Ellenőrizd a bankot és a formátumot a feltöltési képernyőn, vagy töltsd le újra a kivonatot a bankodtól.',
        'truncated_heading' => 'A fájlnak csak egy része volt beolvasható',
        'truncated' => 'A beolvasás a fájl közepén megállt. Ezt a fájlt nem lehet importálni: ha csak a beolvasott rész kerülne mentésre, az időszak többi része hiányozna, és semmi nem jelezné.',
        'truncated_action' => 'Töltse fel újra a fájlt, vagy töltsön le egy friss másolatot a kivonatról a bankjától.',
        'some_rows' => 'Néhány sort nem sikerült beolvasni. Lent meg vannak jelölve, és kimaradnak; a megerősítéssel a többi importálásra kerül.',
        'detail_label' => 'Amit az elemző jelentett:',
        'rows_read_label' => 'Beolvasott sorok',
        'rows_skipped_label' => 'Kihagyott sorok',
    ],
];
