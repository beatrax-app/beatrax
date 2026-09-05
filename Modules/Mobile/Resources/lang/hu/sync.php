<?php

declare(strict_types=1);

return [
    'page_title' => 'Adatok és eszközök',
    'heading' => 'Adatok és eszközök',
    'sync_status' => 'Szinkronizálás állapota',
    'syncing_progress' => 'Szinkronizálás… :count rekord|Szinkronizálás… :count rekord',
    'initial_sync_aria' => 'Az első szinkronizálás előrehaladása',
    'no_peers' => 'Párosíts egy másik eszközt a szinkronizálás elindításához.',
    'sync_now' => 'Szinkronizálás most',
    'result' => [
        'synced' => 'Szinkronizálva a másik eszközöddel.',
        'unreachable' => 'A másik eszköz nem érhető el — ellenőrizd, hogy mindkettő ugyanazon a hálózaton van-e.',
        'locked' => 'Oldd fel az appot a szinkronizáláshoz.',
        'not_enabled' => 'A szinkronizálás még nincs beállítva ezen az eszközön.',
        'unreadable' => 'Ennek az eszköznek a kulcsa már nem nyílik. Párosítsd újra a szinkronizálás folytatásához.',
        'paused_on_cellular' => 'Szüneteltetve — a szinkronizálás csak Wi-Fi-n fut, te pedig mobiladatot használsz.',
    ],
    'background_note' => 'A Beatrax figyel, amíg nyitva van, így egy párosított eszköz bármikor szinkronizálhat ezzel. A Szinkronizálás most gombbal innen indíthatsz adatcserét.',
    'background_note_phone' => 'A szinkronizálás akkor történik, ha a Szinkronizálás most gombra koppintasz. A háttérben nem futhat — az appzár őrzi az egyetlen kulcsot.',
    'network' => 'Hálózat',
    'pause_cellular' => 'Szinkronizálás szüneteltetése mobilhálózaton',
    'pause_cellular_help' => 'Alapértelmezetten kikapcsolva — a szinkronizálás mindenhol működik. Kapcsold be, ha csak Wi-Fi-n szeretnél szinkronizálni.',
];
