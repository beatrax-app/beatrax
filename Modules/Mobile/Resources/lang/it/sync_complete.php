<?php

declare(strict_types=1);

return [
    'page_title' => 'Questo dispositivo è sincronizzato',
    'heading' => 'Questo dispositivo è sincronizzato',
    'records' => 'Copiato :count record da :peer.|Copiati :count record da :peer.',
    'records_none' => "Sei allineato con :peer. Non c'era nulla di nuovo da copiare.",
    'withheld' => ':count modifica non è ancora arrivata.|:count modifiche non sono ancora arrivate.',
    'withheld_action' => 'Firmate da un dispositivo che questo non può verificare. Non si perde nulla — tutto resta su :peer e arriverà se uno dei tuoi dispositivi trasmette quell\'identità e tu la confermi in :section.',
    'how_it_works' => 'Da qui in poi',
    'automatic_title' => 'Decidi tu quando si sincronizza',
    'automatic_body' => 'Tutto ciò che modifichi su un dispositivo compare sull\'altro la volta successiva in cui tocchi :action. Non può girare in background — il blocco app custodisce l\'unica chiave.',
    'lan_title' => 'Sulla stessa rete',
    'lan_body' => 'Quando entrambi i dispositivi sono sulla tua rete di casa comunicano direttamente tra loro, senza nulla in mezzo.',
    'relay_title' => 'Quando sei fuori',
    'relay_body' => 'Le modifiche restano in attesa, crittografate, sul tuo relay finché l\'altro dispositivo non torna online. Questo dispositivo le ritira la volta successiva in cui tocchi :action.',
    'no_relay_title' => 'Quando sei fuori',
    'no_relay_body' => 'Le modifiche restano in attesa su questo dispositivo finché entrambi non sono insieme sulla tua rete di casa e non tocchi :action qui.',
    'encrypted_title' => 'Sigillato tra i tuoi dispositivi',
    'encrypted_body' => 'Tutto ciò che passa tra i tuoi dispositivi è crittografato e solo i tuoi dispositivi abbinati hanno le chiavi. Un relay può vedere quale dei tuoi dispositivi parla con quale, e quando — mai che cosa si dicono.',
    'continue' => 'Inizia a usare Beatrax',
    'peer_fallback' => 'il tuo altro dispositivo',
];
