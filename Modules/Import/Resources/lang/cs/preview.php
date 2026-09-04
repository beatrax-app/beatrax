<?php

declare(strict_types=1);

return [
    'page_title' => 'Náhled importu',
    'heading' => 'Náhled importu',
    'discard' => 'Zahodit import',
    'confirm' => 'Potvrdit import',
    'subtitle' => 'Zkontroluj načtené řádky. Do knihy se nic neuloží, dokud to nepotvrdíš.',

    'already_imported' => 'Tento soubor už byl importován.',

    'already_imported_link' => 'Zobrazit výsledek importu',

    'expired_html' => 'Náhled vypršel. <a href="/imports/new" class="underline">Nahraj soubor znovu</a> a zkus to ještě jednou.',
    'unreadable_html' => 'Náhled nelze přečíst. <a href="/imports/new" class="underline">Nahraj soubor znovu</a> a zkus to ještě jednou.',

    'save_name' => 'Uložit název',
    'account_name_label' => 'Název účtu',
    'account_placeholder' => 'např. Hlavní spořicí účet',
    'rename_aria' => 'Přejmenovat tuto protistranu',

    'unknown_iban_prefix' => 'Našli jsme neznámý IBAN:',

    'unknown_account_prefix' => 'Našli jsme neznámý účet:',
    'unknown_iban_suffix' => 'Pojmenuj tento účet.',

    'ics' => [
        'name' => 'Karta ICS',
        'heading' => 'Pojmenuj svůj účet karty ICS.',
        'help' => 'Data z ICS importuješ poprvé. Dej téhle kartě název, ať se v celé aplikaci zobrazuje jednotně.',
        'placeholder' => 'např. Karta ICS',
    ],

    'paypal' => [
        'name' => 'PayPal',
        'heading' => 'Pojmenuj svůj účet PayPal.',
        'help' => 'Data z PayPalu importuješ poprvé. Dej téhle peněžence název, ať se v celé aplikaci zobrazuje jednotně.',
        'placeholder' => 'např. PayPal',
    ],

    'google_play' => [
        'name' => 'Google Play',
        'heading' => 'Pojmenuj svůj účet Google Play.',
        'help' => 'Účtenku z Google Play importuješ poprvé. Dej tomuhle účtu název, ať se v celé aplikaci zobrazuje jednotně.',
        'placeholder' => 'např. Google Play',
    ],

    'col_date' => 'Datum',
    'col_funding_source' => 'Zdroj financování',
    'col_counterparty' => 'Protistrana',
    'col_amount' => 'Částka',
    'col_status' => 'Stav',

    'status' => [
        'new' => 'Nová',
        'new_title' => 'Přidá se do tvé knihy.',
        'duplicate' => 'Duplicita',
        'duplicate_title' => 'Už naimportováno — přeskočí se.',
        'enriched' => 'Doplněna',
        'enriched_title' => 'Stávající řádek se doplní o silnější odkaz na zdroj.',
        'error' => 'Chyba',
    ],

    'rows_shown' => 'Zobrazené řádky: :shown z :total',

    'show_more' => 'Zobrazit více řádků',

    'errors' => [
        'app_locked' => 'Odemkněte aplikaci pro import: šifrovací klíče nelze použít, dokud je zamčená.',
        'archive_holds_one_message' => 'Tento soubor je jedna e-mailová zpráva, ne archiv schránky, takže přečtený jako archiv v sobě nic nemá. Nahraj ho znovu s formátem E-mailová zpráva.',
        'email_file_is_an_archive' => 'Tento soubor je archiv schránky: obsahuje víc než jednu zprávu a přečtený jako jedna zpráva by z něj vzal jen tu první. Nahraj ho znovu s formátem Archiv schránky.',
        'file_stopped_short' => 'Hlavičkový řádek odpovídal, takže formát je správný. Čtení se zastavilo před koncem souboru. Způsobí to jeden nečitelný řádek i soubor příliš velký pro toto zařízení. Zkus kratší období.',
        'file_unreadable' => 'Tento soubor se nepodařilo načíst.',
        'file_unreadable_detail' => 'Aplikace nedokázala načíst tento soubor (:code). Úplné podrobnosti jsou v protokolu aplikace; při hlášení problému uveďte tento kód.',
        'iban_not_in_preview' => 'Tento IBAN není součástí aktuálního náhledu.',
        'not_an_email_file' => 'Tento soubor není ani e-mailová zpráva, ani archiv schránky, takže v něm není co číst jako účtenku. Vyber typ importu a formát, které odpovídají tvému souboru.',
        'pdf_has_no_text_layer' => 'Tento PDF neobsahuje žádný text — je to sken nebo fotka výpisu, takže v něm není co číst. Stáhni si samotný výpis z banky nebo použij export CSV.',
        'pdf_password_protected' => 'Tento PDF je chráněný heslem, takže ho neotevře žádná čtečka. Ulož si v prohlížeči PDF nechráněnou kopii a naimportuj ji.',
        'pdf_reader_unavailable' => 'Tato verze aplikace nemá vůbec žádnou čtečku PDF, takže výpis v PDF zde nelze otevřít. Naimportuj tento soubor na jiném zařízení nebo použij export CSV z banky.',
        'row_belongs_to_another_statement' => 'Tento řádek patří k transakci v jiném souboru výpisu. Naimportujte i tento výpis — oba se čtou společně.',
        'row_unreadable' => 'Tento řádek se nepodařilo načíst.',
        'row_unreadable_detail' => 'Aplikace nedokázala načíst tento řádek (:code). Úplné podrobnosti jsou v protokolu aplikace; při hlášení problému uveďte tento kód.',
        'unknown_account' => 'Tento řádek patří k účtu, kterému jsi ještě nedal jméno.',
    ],

    'receipts' => [
        'heading' => 'Tento soubor byl přečten jako e-mail',
        'saved' => 'Co obsahoval, je vypsané níže, a každá zpráva je uložená.',
        'none_imported' => 'Nic z toho se nestalo transakcí, takže do knihy nebylo nic přidáno.',
        'shown' => 'Zobrazené zprávy: :shown z :total',
        'no_subject' => 'Bez předmětu',

        'state' => [
            'read' => 'Přečteno jako platba — potvrď tento import, aby se dostala do knihy.',
            'not_a_payment' => 'Není to platba. Tato zpráva něco oznamuje, místo aby platbu potvrzovala.',
            'unreadable' => 'Uloženo. Aplikace účtenky od tohoto odesílatele čte, ale v této zprávě nenašla částku, obchodníka ani referenci.',
            'unknown_sender' => 'Uloženo. Aplikace účtenky od tohoto odesílatele nečte, takže si ze zprávy nic nevzala.',
        ],
    ],

    'failed' => [
        'heading' => 'Tento soubor se nepodařilo načíst',
        'no_rows' => 'V tomto souboru nebyly nalezeny žádné transakce, takže není co importovat.',
        'nothing_read' => 'Nic v tomto souboru se nepodařilo načíst jako transakci, takže není co importovat.',
        'every_row' => 'Žádný řádek tohoto souboru se nepodařilo načíst, takže není co importovat. Každý řádek je níže i s důvodem.',
        'likely_cause' => 'Obvykle hlavičkový řádek neodpovídá zdroji, který jsi vybral. Zkontroluj banku a formát na obrazovce nahrávání, nebo si výpis z banky stáhni znovu.',
        'truncated_heading' => 'Z tohoto souboru se podařilo načíst jen část',
        'truncated' => 'Čtení se zastavilo uprostřed souboru. Vše za tímto místem nebylo načteno a nebude importováno.',
        'some_rows' => 'Některé řádky se nepodařilo načíst. Jsou níže označeny a budou přeskočeny; potvrzením se naimportuje zbytek.',
        'detail_label' => 'Co ohlásil parser:',
        'rows_read_label' => 'Načtené řádky',
        'rows_skipped_label' => 'Přeskočené řádky',
    ],
];
