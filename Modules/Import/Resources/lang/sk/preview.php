<?php

declare(strict_types=1);

return [
    'page_title' => 'Náhľad importu',
    'heading' => 'Náhľad importu',
    'discard' => 'Zahodiť import',
    'confirm' => 'Potvrdiť import',
    'subtitle' => 'Skontroluj načítané riadky. Do knihy sa nič neuloží, kým to nepotvrdíš.',

    'already_imported' => 'Tento súbor už bol importovaný.',

    'already_imported_link' => 'Zobraziť výsledok importu',

    'expired_html' => 'Náhľad expiroval. <a href="/imports/new" class="underline">Nahraj súbor znova</a> a skús to ešte raz.',
    'unreadable_html' => 'Náhľad sa nedá prečítať. <a href="/imports/new" class="underline">Nahraj súbor znova</a> a skús to ešte raz.',

    'save_name' => 'Uložiť názov',
    'account_name_label' => 'Názov účtu',
    'account_placeholder' => 'napr. Hlavný sporiaci účet',
    'rename_aria' => 'Premenovať túto protistranu',

    'unknown_iban_prefix' => 'Našli sme neznámy IBAN:',

    'unknown_account_prefix' => 'Našli sme neznámy účet:',
    'unknown_iban_suffix' => 'Pomenuj tento účet.',

    'ics' => [
        'name' => 'Karta ICS',
        'heading' => 'Pomenuj svoj kartový účet ICS.',
        'help' => 'Toto je prvý import údajov ICS. Daj tejto karte názov, aby sa v celej aplikácii zobrazovala jednotne.',
        'placeholder' => 'napr. Karta ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Pomenuj svoj účet PayPal.',
        'help' => 'Toto je prvý import údajov z PayPalu. Daj tejto peňaženke názov, aby sa v celej aplikácii zobrazovala jednotne.',
        'placeholder' => 'napr. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Pomenuj svoj účet Google Play.',
        'help' => 'Toto je prvý import účtenky z Google Play. Daj tomuto účtu názov, aby sa v celej aplikácii zobrazoval jednotne.',
        'placeholder' => 'napr. Google Play',
    ],

    'col_date' => 'Dátum',
    'col_funding_source' => 'Zdroj financovania',
    'col_counterparty' => 'Protistrana',
    'col_amount' => 'Suma',
    'col_status' => 'Stav',

    'status' => [
        'new' => 'Nová',
        'new_title' => 'Pridá sa do tvojej knihy.',
        'duplicate' => 'Duplikát',
        'duplicate_title' => 'Už importované — preskočí sa.',
        'enriched' => 'Doplnená',
        'enriched_title' => 'Existujúci riadok sa doplní o silnejší odkaz na zdroj.',
        'error' => 'Chyba',
    ],

    'rows_shown' => 'Zobrazené riadky: :shown z :total',

    'show_more' => 'Zobraziť viac riadkov',

    'errors' => [
        'app_locked' => 'Odomknite aplikáciu na import: šifrovacie kľúče sa nedajú použiť, kým je zamknutá.',
        'archive_holds_one_message' => 'Tento súbor je jedna e-mailová správa, nie archív schránky, takže prečítaný ako archív v sebe nič nemá. Nahraj ho znova s formátom E-mailová správa.',
        'email_file_is_an_archive' => 'Tento súbor je archív schránky: obsahuje viac než jednu správu a prečítaný ako jedna správa by z neho vzal len tú prvú. Nahraj ho znova s formátom Archív schránky.',
        'file_stopped_short' => 'Hlavičkový riadok zodpovedal, takže formát je správny. Čítanie sa zastavilo pred koncom súboru. Spôsobí to jeden nečitateľný riadok aj súbor príliš veľký pre toto zariadenie. Skús kratšie obdobie.',
        'file_unreadable' => 'Tento súbor sa nepodarilo načítať.',
        'file_unreadable_detail' => 'Aplikácia nedokázala načítať tento súbor (:code). Úplné podrobnosti sú v protokole aplikácie; pri hlásení problému uveďte tento kód.',
        'iban_not_in_preview' => 'Tento IBAN nie je súčasťou aktuálneho náhľadu.',
        'message_unreadable' => 'Túto správu sa nepodarilo načítať, a preto bola preskočená.',
        'not_an_email_file' => 'Tento súbor nie je ani e-mailová správa, ani archív schránky, takže v ňom nie je čo čítať ako účtenku. Vyber typ importu a formát, ktoré zodpovedajú tvojmu súboru.',
        'pdf_has_no_text_layer' => 'Tento PDF neobsahuje žiadny text — je to sken alebo fotka výpisu, takže v ňom nie je čo čítať. Stiahni si samotný výpis z banky alebo použi export CSV.',
        'pdf_password_protected' => 'Tento PDF je chránený heslom, takže ho neotvorí žiadna čítačka. Ulož si v prehliadači PDF nechránenú kópiu a naimportuj ju.',
        'pdf_reader_unavailable' => 'Táto verzia aplikácie nemá vôbec žiadnu čítačku PDF, takže výpis v PDF sa tu nedá otvoriť. Naimportuj tento súbor na inom zariadení alebo použi export CSV z banky.',
        'row_belongs_to_another_statement' => 'Tento riadok patrí k transakcii v inom súbore výpisu. Naimportujte aj tento výpis — oba sa čítajú spoločne.',
        'row_unreadable' => 'Tento riadok sa nepodarilo načítať.',
        'row_unreadable_detail' => 'Aplikácia nedokázala načítať tento riadok (:code). Úplné podrobnosti sú v protokole aplikácie; pri hlásení problému uveďte tento kód.',
        'unknown_account' => 'Tento riadok patrí k účtu, ktorému si ešte nedal názov.',
    ],

    'refused' => [
        'accounts_to_name' => 'Tento súbor čaká, kým pomenuješ účet, ku ktorému patria jeho riadky.',
        'file_did_not_read_in_full' => 'Tento súbor sa nepodarilo prečítať až do konca.',
        'nothing_importable' => 'Z tohto súboru sa nedá nič importovať.',
        'preview_expired' => 'Náhľad tohto súboru je príliš starý na to, aby sa teraz uložil. Nahraj ho znova.',
    ],

    'receipts' => [
        'heading' => 'Tento súbor sa prečítal ako e-mail',
        'saved' => 'Čo obsahoval, je vypísané nižšie, a každá správa je uložená.',
        'none_imported' => 'Nič z toho sa nestalo transakciou, takže do knihy nepribudlo nič.',
        'shown' => 'Zobrazené správy: :shown z :total',
        'no_subject' => 'Bez predmetu',

        'state' => [
            'read' => 'Prečítané ako platba — potvrď tento import, aby sa dostala do knihy.',
            'not_a_payment' => 'Nie je to platba. Táto správa niečo oznamuje namiesto toho, aby platbu potvrdila.',
            'unreadable' => 'Uložené. Aplikácia číta účtenky od tohto odosielateľa, ale v tejto správe nenašla sumu, obchodníka ani referenciu.',
            'unknown_sender' => 'Uložené. Aplikácia nečíta účtenky od tohto odosielateľa, takže si zo správy nič nevzala.',
        ],
    ],

    'failed' => [
        'heading' => 'Tento súbor sa nepodarilo načítať',
        'no_rows' => 'V tomto súbore sa nenašli žiadne transakcie, takže nie je čo importovať.',
        'nothing_read' => 'Nič v tomto súbore sa nepodarilo načítať ako transakciu, takže nie je čo importovať.',
        'every_row' => 'Žiadny riadok tohto súboru sa nepodarilo načítať, takže nie je čo importovať. Každý riadok je nižšie aj s dôvodom.',
        'likely_cause' => 'Zvyčajne hlavičkový riadok nezodpovedá zdroju, ktorý si vybral. Skontroluj banku a formát na obrazovke nahrávania, alebo si výpis z banky stiahni znova.',
        'truncated_heading' => 'Z tohto súboru sa podarilo načítať len časť',
        'truncated' => 'Čítanie sa zastavilo uprostred súboru. Tento súbor nemožno importovať: uložiť len načítanú časť by znamenalo, že zvyšok obdobia bude chýbať a nič na to neupozorní.',
        'truncated_action' => 'Nahrajte súbor znova alebo si stiahnite novú kópiu výpisu zo svojej banky.',
        'some_rows' => 'Niektoré riadky sa nepodarilo načítať. Sú nižšie označené a budú preskočené; potvrdením sa naimportuje zvyšok.',
        'detail_label' => 'Čo ohlásil parser:',
        'rows_read_label' => 'Načítané riadky',
        'rows_skipped_label' => 'Preskočené riadky',
    ],
];
