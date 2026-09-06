<?php

declare(strict_types=1);

return [
    'page_title' => 'Toto zařízení je synchronizované',
    'heading' => 'Toto zařízení je synchronizované',
    'records' => 'Zkopírován :count záznam z :peer.|Zkopírovány :count záznamy z :peer.|Zkopírováno :count záznamů z :peer.',
    'records_none' => 'Vše je aktuální — nebylo co kopírovat. Zdroj: :peer.',
    'withheld' => ':count změna zatím nedorazila.|:count změny zatím nedorazily.|:count změn zatím nedorazilo.',
    'withheld_action' => 'Podepsalo je zařízení, které toto zařízení nemůže ověřit. Nic není ztraceno — vše zůstává na zařízení :peer a dorazí, jakmile některé tvé zařízení předá tuto identitu a ty ji potvrdíš v části :section.',
    'how_it_works' => 'Od téhle chvíle',
    'automatic_title' => 'Kdy se synchronizuje, určuješ ty',
    'automatic_body' => 'Cokoli změníš na jednom zařízení, objeví se na druhém, až příště klepneš na :action. Na pozadí běžet nemůže — zámek aplikace drží jediný klíč.',
    'lan_title' => 'Ve stejné síti',
    'lan_body' => 'Když jsou obě zařízení v tvé domácí síti, komunikují spolu přímo, bez čehokoli mezi tím.',
    'relay_title' => 'Když jsi pryč',
    'relay_body' => 'Změny čekají zašifrované na tvém relay serveru, dokud se druhé zařízení nevrátí online. Toto zařízení si je vyzvedne, až příště klepneš na :action.',
    'no_relay_title' => 'Když jsi pryč',
    'no_relay_body' => 'Změny počkají na tomto zařízení, dokud nebudou obě spolu v tvé domácí síti a dokud tu neklepneš na :action.',
    'encrypted_title' => 'Zapečetěné mezi tvými zařízeními',
    'encrypted_body' => 'Všechno, co mezi tvými zařízeními putuje, je zašifrované a klíče mají jen tvá spárovaná zařízení. Relay vidí, které z tvých zařízení mluví s kterým a kdy — nikdy to, co si říkají.',
    'continue' => 'Začít používat Beatrax',
    'peer_fallback' => 'tvé druhé zařízení',
];
