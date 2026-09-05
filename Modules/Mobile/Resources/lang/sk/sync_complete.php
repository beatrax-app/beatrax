<?php

declare(strict_types=1);

return [
    'page_title' => 'Toto zariadenie je zosynchronizované',
    'heading' => 'Toto zariadenie je zosynchronizované',
    'records' => 'Skopírovaný :count záznam zo zariadenia :peer.|Skopírované :count záznamy zo zariadenia :peer.|Skopírovaných :count záznamov zo zariadenia :peer.',
    'records_none' => 'Všetko je aktuálne, nebolo čo kopírovať. Zdroj: :peer.',
    'withheld' => ':count zmena zatiaľ nedorazila.|:count zmeny zatiaľ nedorazili.|:count zmien zatiaľ nedorazilo.',
    'withheld_action' => 'Podpísalo ich zariadenie, ktoré toto zariadenie nedokáže overiť. Nič sa nestráca — všetko zostáva na zariadení :peer a dorazí, keď niektoré tvoje zariadenie odovzdá tú identitu a ty ju potvrdíš v časti :section.',
    'how_it_works' => 'Odteraz',
    'automatic_title' => 'Ty rozhoduješ, kedy sa synchronizuje',
    'automatic_body' => 'Čokoľvek zmeníš na jednom zariadení, objaví sa aj na druhom, keď nabudúce klepneš na :action. Na pozadí bežať nemôže — zámok aplikácie drží jediný kľúč.',
    'lan_title' => 'V rovnakej sieti',
    'lan_body' => 'Keď sú obe zariadenia v tvojej domácej sieti, komunikujú priamo medzi sebou, bez čohokoľvek uprostred.',
    'relay_title' => 'Keď si mimo domu',
    'relay_body' => 'Zmeny čakajú zašifrované na tvojom relé, kým sa druhé zariadenie nevráti online. Toto zariadenie si ich prevezme, keď nabudúce klepneš na :action.',
    'no_relay_title' => 'Keď si mimo domu',
    'no_relay_body' => 'Zmeny čakajú na tomto zariadení, kým nebudú obe naraz v tvojej domácej sieti a kým tu neklepneš na :action.',
    'encrypted_title' => 'Prečítať to dokážu len tvoje zariadenia',
    'encrypted_body' => 'Všetko sa zašifruje ešte pred odchodom zo zariadenia a kľúče majú len tvoje spárované zariadenia.',
    'continue' => 'Začať používať Beatrax',
    'peer_fallback' => 'tvoje druhé zariadenie',
];
