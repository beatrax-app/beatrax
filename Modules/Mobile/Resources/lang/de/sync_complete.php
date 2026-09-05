<?php

declare(strict_types=1);

return [
    'page_title' => 'Dieses Gerät ist synchronisiert',
    'heading' => 'Dieses Gerät ist synchronisiert',
    'records' => ':count Datensatz von :peer kopiert.|:count Datensätze von :peer kopiert.',
    'records_none' => 'Auf dem Stand von :peer. Es gab nichts Neues zu kopieren.',
    'withheld' => ':count Änderung ist noch nicht angekommen.|:count Änderungen sind noch nicht angekommen.',
    'withheld_action' => 'Signiert von einem Gerät, das dieses Gerät nicht prüfen kann. Nichts geht verloren — alles bleibt auf :peer und kommt an, sobald eines deiner Geräte diese Identität weitergibt und du sie unter :section bestätigst.',
    'how_it_works' => 'Ab jetzt',
    'automatic_title' => 'Du entscheidest, wann synchronisiert wird',
    'automatic_body' => 'Alles, was du auf einem der Geräte änderst, erscheint auf dem anderen, sobald du das nächste Mal auf :action tippst. Im Hintergrund geht das nicht — die App-Sperre hält den einzigen Schlüssel.',
    'lan_title' => 'Im selben Netzwerk',
    'lan_body' => 'Wenn beide Geräte in deinem Heimnetz sind, sprechen sie direkt miteinander, ohne etwas dazwischen.',
    'relay_title' => 'Wenn du unterwegs bist',
    'relay_body' => 'Änderungen warten verschlüsselt auf deinem Relay, bis das andere Gerät wieder online ist. Dieses Gerät holt sie ab, sobald du das nächste Mal auf :action tippst.',
    'no_relay_title' => 'Wenn du unterwegs bist',
    'no_relay_body' => 'Änderungen warten auf diesem Gerät, bis beide gemeinsam in deinem Heimnetz sind und du hier auf :action tippst.',
    'encrypted_title' => 'Nur deine Geräte können es lesen',
    'encrypted_body' => 'Alles wird verschlüsselt, bevor es ein Gerät verlässt, und nur deine gekoppelten Geräte haben die Schlüssel.',
    'continue' => 'Beatrax nutzen',
    'peer_fallback' => 'deinem anderen Gerät',
];
