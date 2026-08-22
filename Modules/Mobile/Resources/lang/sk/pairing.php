<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Spárované zariadenie',
    'page_title' => 'Spárovať zariadenie',

    'scan_heading' => 'Spáruj toto zariadenie',
    'scan_subtitle' => 'Namier fotoaparát na kód zobrazený na druhom zariadení.',
    'camera_permission_pending' => 'Prístup k fotoaparátu je vypnutý. Povoľ ho pre Beatrax v nastaveniach zariadenia a skús to znova.',
    'open_camera' => 'Otvoriť fotoaparát',
    'opening_camera' => 'Čaká sa na prístup k fotoaparátu…',
    'close_camera' => 'Zavrieť fotoaparát',
    'viewfinder_aria' => 'Hľadáčik fotoaparátu — namier ho na kód na druhom zariadení',
    'viewfinder_idle' => 'Fotoaparát je vypnutý. Otvor ho a naskenuj kód zobrazený na druhom zariadení.',
    'scan_prompt' => 'Naskenuj kód na druhom zariadení',
    'enter_code_instead' => 'Zadať kód namiesto skenovania',

    'enter_heading' => 'Zadaj kód',
    'camera_off' => 'Prístup k fotoaparátu je vypnutý. Zadaj namiesto toho kód z druhého zariadenia.',
    'word_code_aria' => 'Zadaj slovný kód z druhého zariadenia',
    'submit_code' => 'Odoslať kód',
    'cancel' => 'Zrušiť',
    'skip_import' => 'Pokračovať bez importu',

    'confirm_heading' => 'Porovnaj tieto slová s druhým zariadením',
    'safety_words_aria' => 'Slová bezpečnostného čísla: :words',
    'confirm_body' => 'Obe zariadenia musia ukazovať presne tie isté slová. Ak sa líšia, ťukni na Zrušiť — môže prebiehať útok man-in-the-middle.',
    'awaiting_peer' => 'Čaká sa na potvrdenie z druhého zariadenia...',
    'confirm_match' => 'Potvrdiť — zhodujú sa',

    'success_heading' => 'Zariadenie spárované',
    'success_body' => 'Tomuto zariadeniu sa teraz dôveruje. Po pripojení sa tvoje údaje zosynchronizujú.',
    'done' => 'Hotovo',

    'errors' => [
        'relay_unreachable' => 'Druhé zariadenie sa nedá dosiahnuť. Skontroluj, či sú obe v tej istej sieti a či je na počítači zapnutá synchronizácia.',
        'invalid_code' => 'Tento kód je neplatný alebo mu vypršala platnosť. Nechaj si na druhom zariadení vygenerovať nový.',
        'code_not_accepted' => 'Žiadne zariadenie v tejto sieti tento kód neprijalo. Skontroluj kód a či ho druhé zariadenie stále zobrazuje.',
        'no_peer_answered' => 'V tejto sieti na tento kód nič neodpovedalo. Skontroluj, či na druhom zariadení beží synchronizácia, alebo naskenuj jeho kód fotoaparátom — ten sieť prehľadávať nemusí.',
        'no_peer_answered_ios' => 'V tejto sieti na tento kód nič neodpovedalo. Vyhľadanie druhého zariadenia v sieti na iPhone zatiaľ nefunguje, takže naskenuj jeho kód fotoaparátom.',
        'rate_limited' => 'Príliš veľa pokusov. Počkaj minútu a skús to znova.',
        'identity_locked' => 'Identita tvojho zariadenia je zamknutá. Odomkni aplikáciu a skús to znova.',
        'identity_needs_lock' => 'Najprv nastavte zámok aplikácie — chráni identitu vášho zariadenia.',
        'safety_number_changed' => 'Druhé zariadenie sa počas porovnávania zmenilo. Skôr než potvrdíš, skontroluj slová nižšie znova.',
    ],
];
