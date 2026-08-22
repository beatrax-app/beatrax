<?php

declare(strict_types=1);

return [
    'page_title' => 'Náhľad importu',
    'heading' => 'Náhľad importu',
    'discard' => 'Zahodiť import',
    'confirm' => 'Potvrdiť import',
    'subtitle' => 'Skontroluj načítané riadky. Do knihy sa nič neuloží, kým to nepotvrdíš.',

    'expired_html' => 'Náhľad expiroval. <a href="/imports/new" class="underline">Nahraj súbor znova</a> a skús to ešte raz.',

    'save_name' => 'Uložiť názov',
    'account_name_label' => 'Názov účtu',
    'account_placeholder' => 'napr. Hlavný sporiaci účet',
    'rename_aria' => 'Premenovať túto protistranu',

    'unknown_iban_prefix' => 'Našli sme neznámy IBAN:',
    'unknown_iban_suffix' => 'Pomenuj tento účet.',

    'ics' => [
        'heading' => 'Pomenuj svoj kartový účet ICS.',
        'help' => 'Toto je prvý import údajov ICS. Daj tejto karte názov, aby sa v celej aplikácii zobrazovala jednotne.',
        'placeholder' => 'napr. Karta ICS',
    ],

    'paypal' => [
        'heading' => 'Pomenuj svoj účet PayPal.',
        'help' => 'Toto je prvý import údajov z PayPalu. Daj tejto peňaženke názov, aby sa v celej aplikácii zobrazovala jednotne.',
        'placeholder' => 'napr. PayPal',
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

    'chain' => [
        'heading' => 'Riešia sa reťazce…',
        'pending' => 'Vo fronte. Riešenie reťazcov sa čoskoro spustí.',
        'running' => 'Prepájajú sa reťazce financovania a rozkladajú sa zúčtovania z výpisu z účtu.',
        'failed_prefix' => 'Riešenie reťazcov zlyhalo:',
        'failed_detail' => 'podrobnosti sú v protokole úloh',
        'open_horizon' => 'Otvoriť Horizon',
        'failed_suffix' => 'na opakovanie alebo kontrolu.',
    ],

    'errors' => [
        'app_locked' => 'Odomknite aplikáciu na import: šifrovacie kľúče sa nedajú použiť, kým je zamknutá.',
        'file_unreadable' => 'Tento súbor sa nepodarilo načítať.',
        'iban_not_in_preview' => 'Tento IBAN nie je súčasťou aktuálneho náhľadu.',
        'row_unreadable' => 'Tento riadok sa nepodarilo načítať.',
        'unknown_account' => 'Tento riadok patrí k účtu, ktorému si ešte nedal názov.',
    ],

    'failed' => [
        'heading' => 'Tento súbor sa nepodarilo načítať',
        'no_rows' => 'V tomto súbore sa nenašli žiadne transakcie, takže nie je čo importovať.',
        'nothing_read' => 'Nič v tomto súbore sa nepodarilo načítať ako transakciu, takže nie je čo importovať.',
        'every_row' => 'Žiadny riadok tohto súboru sa nepodarilo načítať, takže nie je čo importovať. Každý riadok je nižšie aj s dôvodom.',
        'likely_cause' => 'Zvyčajne hlavičkový riadok nezodpovedá zdroju, ktorý si vybral. Skontroluj banku a formát na obrazovke nahrávania, alebo si výpis z banky stiahni znova.',
        'truncated_heading' => 'Z tohto súboru sa podarilo načítať len časť',
        'truncated' => 'Čítanie sa zastavilo uprostred súboru. Všetko za týmto miestom nebolo načítané a nebude importované.',
        'some_rows' => 'Niektoré riadky sa nepodarilo načítať. Sú nižšie označené a budú preskočené; potvrdením sa naimportuje zvyšok.',
        'detail_label' => 'Čo ohlásil parser:',
        'rows_read_label' => 'Načítané riadky',
        'rows_skipped_label' => 'Preskočené riadky',
    ],
];
