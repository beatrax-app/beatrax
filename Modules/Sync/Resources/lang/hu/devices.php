<?php

declare(strict_types=1);

return [
    'heading' => 'Eszközök és szinkronizálás',

    'enable_sync' => 'Szinkronizálás bekapcsolása',
    'enable_sync_help' => 'Oszd meg az adataidat biztonságosan a megbízható eszközeid között. Alkalmazászár szükséges hozzá.',

    'app_lock_notice' => 'A szinkronizálás bekapcsolásához előbb állíts be alkalmazászárat.',
    'go_to_app_lock' => 'Ugrás az alkalmazászárhoz',

    'encrypted_at_rest' => 'Az adatok nyugalmi állapotban titkosítva',
    'encrypted_at_rest_help' => 'Az adataidat az alkalmazászár jelmondata védi.',
    'on' => 'Be',
    'securing' => 'Az adataid védelme…',
    'do_not_close' => 'Ne zárd be ezt az ablakot.',
    'encryption_progress_aria' => 'Titkosítás folyamata',
    'not_encrypted_offer' => 'Az adataid nyugalmi állapotban nincsenek titkosítva. Állítsd be a titkosítást, hogy védve legyenek, ha ez az eszköz elvész vagy ellopják.',
    'enable_encryption' => 'Titkosítás bekapcsolása',

    'your_devices' => 'Az eszközeid',

    'moved_help' => 'A párosítás, az eszköznevek és a titkosítás mostantól a szinkronizálás állapotánál található.',
    'moved_cta' => 'Szinkronizálás és eszköz megnyitása',
    'device_name' => 'Eszköz neve',
    'save' => 'Mentés',
    'peer_default_name' => 'Párosított eszköz',
    'rename_device' => 'Eszköz átnevezése',
    'this_device' => 'Ez az eszköz',
    'removed' => 'Eltávolítva',
    'confirmed' => 'Megerősítve',
    'awaiting_confirmation' => 'Megerősítésre vár',
    'safety_number_words' => 'Biztonsági számszavak:',
    'paired' => 'Párosítva',
    'remove_aria' => ':name eltávolítása',
    'remove' => 'Eltávolítás',
    'pair_new_device' => 'Új eszköz párosítása',

    'relay_endpoint' => 'Relé végpont',
    'relay_endpoint_help' => 'Opcionális. Ha meg van adva, az offline eszközök ezen a relén keresztül szinkronizálnak. Hagyd üresen a csak LAN&#8209;közvetlen módhoz.',
    'relay_endpoint_aria' => 'A relé végpont URL-je',
    'relay_insecure_warning' => 'Ez a relé végpont sima HTTP-t használ. Bár a relé soha nem fejti vissza az adataidat, a nem biztonságos kapcsolat felfedi a titkosított adatok méretét és időzítését a hálózatot figyelők előtt. A legjobb adatvédelemhez használj <strong>https://</strong> végpontot.',

    'enable_at_rest' => 'Nyugalmi titkosítás bekapcsolása',
    'enable_at_rest_body' => 'Az adataidat az alkalmazászár jelmondatával titkosítjuk. A migrálás előtt automatikusan biztonsági mentés készül.',
    'no_recovery_warning' => 'Ha elveszíted az alkalmazászár jelmondatát, és nincs biztonsági mentésed vagy másik megbízható eszközöd, az adataid nem állíthatók helyre.',
    'recover_help' => 'A hozzáférés visszaszerzéséhez párosítsd újra ezt az eszközt egy másik megbízható eszközről, vagy használd a saját titkosított biztonsági mentésedet.',
    'amounts_plaintext' => 'Az összegek nyugalmi állapotban nincsenek titkosítva — az egyenlegek és a végösszegek olvashatók maradnak, hogy a havi összesítéseid továbbra is helyesen álljanak össze.',
    'search_plaintext' => 'A keresési index titkosítatlan másolatot őriz a kereskedő és a leírás szövegéből, hogy a teljes szöveges keresés továbbra is működjön.',
    'keep_unencrypted' => 'Adatok titkosítatlanul hagyása',
    'encryption_enabled' => 'Titkosítás bekapcsolva',
    'encryption_enabled_body' => 'Az adataid mostantól nyugalmi állapotban titkosítva vannak.',
    'done_encryption_enabled' => 'Kész — a titkosítás bekapcsolva',
    'encryption_failed' => 'A titkosítás beállítása nem sikerült',
    'encryption_failed_body' => 'Az adataid nem változtak. A biztonsági mentésed megmaradt.',
    'close_no_changes' => 'Bezárás — nem történt módosítás',

    'remove_this_device' => 'Ennek az eszköznek az eltávolítása',
    'removing' => 'Eltávolítás:',
    'remove_rotates_key' => 'Az eszköz eltávolítása lecseréli a titkosítási kulcsot, így az eszköz nem kap több frissítést.',
    'remove_cannot_erase' => 'A már az eszközön lévő adatokat ez nem törli. Ha ez az eszköz elveszett vagy ellopták, tekintsd az összes rajta lévő adatot nyilvánosságra kerültnek.',
    'remove_device' => 'Eszköz eltávolítása',
    'keep_device' => 'Eszköz megtartása',
    'rotating_key' => 'A titkosítási kulcs cseréje…',

    'flash' => [
        'app_lock_first' => 'A szinkronizálás bekapcsolásához előbb állíts be alkalmazászárat.',
        'enable_failed' => 'A szinkronizálást nem sikerült bekapcsolni. Győződj meg róla, hogy az alkalmazászár aktív, és próbáld újra.',
        'cannot_remove_self' => 'Ezt az eszközt nem távolíthatod el — éppen ezt használod.',
        'remove_failed' => 'Az eszközt nem sikerült eltávolítani. Próbáld újra.',
        'app_lock_first_settings' => 'A szinkronizálási beállítások módosításához előbb állíts be alkalmazászárat.',
        'relay_cleared' => 'A relé végpont törölve.',
        'relay_saved' => 'A relé végpont mentve.',
        'relay_save_failed' => 'A relé végpontot nem sikerült menteni: :message',
    ],
];
