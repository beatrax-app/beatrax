<?php

declare(strict_types=1);

return [
    'page_title' => 'Ez az eszköz szinkronizálva van',
    'heading' => 'Ez az eszköz szinkronizálva van',
    'records' => ':count rekord átmásolva innen: :peer.|:count rekord átmásolva innen: :peer.',
    'records_none' => 'Naprakész ezzel: :peer. Nem volt új másolnivaló.',
    'withheld' => ':count módosítás még nem érkezett meg.|:count módosítás még nem érkezett meg.',
    'withheld_action' => 'Olyan eszköz írta alá őket, amelyet ez az eszköz nem tud ellenőrizni. Semmi nem vész el — minden a(z) :peer eszközön marad, és megérkezik, ha valamelyik eszközöd továbbadja azt az azonosságot, és te megerősíted a(z) :section részben.',
    'how_it_works' => 'Mostantól',
    'automatic_title' => 'Te döntöd el, mikor szinkronizál',
    'automatic_body' => 'Amit bármelyik eszközön módosítasz, megjelenik a másikon, amikor legközelebb a :action gombra koppintasz. A háttérben nem futhat — az appzár őrzi az egyetlen kulcsot.',
    'lan_title' => 'Ugyanazon a hálózaton',
    'lan_body' => 'Amikor mindkét eszköz az otthoni hálózatodon van, közvetlenül beszélnek egymással, közbeiktatott szereplő nélkül.',
    'relay_title' => 'Amikor úton vagy',
    'relay_body' => 'A módosítások titkosítva várakoznak a reléden, amíg a másik eszköz újra online nem lesz. Ez az eszköz akkor veszi át őket, amikor legközelebb a :action gombra koppintasz.',
    'no_relay_title' => 'Amikor úton vagy',
    'no_relay_body' => 'A módosítások ezen az eszközön várakoznak, amíg mindkettő együtt nem lesz az otthoni hálózatodon, és itt a :action gombra nem koppintasz.',
    'encrypted_title' => 'Csak a te eszközeid tudják elolvasni',
    'encrypted_body' => 'Minden titkosítva lesz, mielőtt elhagyja az eszközt, és a kulcsok csak a párosított eszközeidnél vannak meg.',
    'continue' => 'A Beatrax használatának megkezdése',
    'peer_fallback' => 'a másik eszközöd',
];
