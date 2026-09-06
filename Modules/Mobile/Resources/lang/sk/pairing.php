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
    'camera_off_no_search' => 'Prístup k fotoaparátu je vypnutý a hľadanie druhého zariadenia v sieti na iPhone zatiaľ nefunguje — takže napísaný kód ho sám nenájde. Zapni Beatraxu prístup k fotoaparátu späť v nastaveniach zariadenia a naskenuj kód zobrazený na druhom zariadení, alebo odošli kód tu a táto obrazovka sa spýta, kde je.',
    'no_search' => 'Hľadanie druhého zariadenia v sieti na iPhone zatiaľ nefunguje, takže napísaný kód ho sám nenájde. Naskenuj kód fotoaparátom — ten žiadne hľadanie v sieti nepotrebuje. Ak skenovať nemôžeš, odošli kód a táto obrazovka sa spýta, kde druhé zariadenie je.',
    'word_code_aria' => 'Zadaj slovný kód z druhého zariadenia',
    'initiator_address' => 'Kde je druhé zariadenie?',
    'initiator_address_help' => 'Jeho adresa v tejto sieti, ako host a port. Počítač ju zobrazuje v časti Zariadenia a synchronizácia. Keď ju zadáš, odošli kód znova.',
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
    'encryption_incomplete' => 'Zariadenie je spárované, ale šifrovanie údajov uložených v ňom sa nedokončilo. Údaje sa zatiaľ neukladajú zašifrované.',
    'done' => 'Hotovo',

    'errors' => [
        'relay_unreachable' => 'Druhé zariadenie sa nedá dosiahnuť. Skontroluj, či sú obe v tej istej sieti a či je na počítači zapnutá synchronizácia.',
        'no_road_home' => 'Toto zariadenie nedokáže prehľadávať sieť a kód, ktorý si naskenoval, neobsahuje adresu druhého zariadenia. Požiadaj ho o nový kód a naskenuj ten.',
        'invalid_code' => 'Tento kód je neplatný alebo mu vypršala platnosť. Nechaj si na druhom zariadení vygenerovať nový.',
        'already_under_way' => 'Toto zariadenie už kód prijalo a čaká na potvrdenie z druhého zariadenia. Ak nepríde, nechaj vygenerovať nový kód a použi ten.',
        'vouched_but_refused' => 'Druhé zariadenie kód stále má, ale toto zariadenie ho nedokázalo prijať. Nechaj na ňom vygenerovať nový kód a použi ten.',
        'code_incomplete' => 'Tento kód nie je úplný. Porovnaj ho s druhým zariadením a zadaj ho celý.',
        'initiator_address_invalid' => 'To nie je adresa, na ktorú sa toto zariadenie dovolá. Zadaj ju ako host a port, napríklad 192.168.1.20:8100.',
        'code_not_accepted' => 'Žiadne zariadenie v tejto sieti tento kód neprijalo. Skontroluj kód a či ho druhé zariadenie stále zobrazuje.',
        'no_peer_answered' => 'V tejto sieti na tento kód nič neodpovedalo. Skontroluj, či na druhom zariadení beží synchronizácia, alebo naskenuj jeho kód fotoaparátom — ten sieť prehľadávať nemusí.',
        'no_peer_answered_ios' => 'V tejto sieti na tento kód nič neodpovedalo. Vyhľadanie druhého zariadenia v sieti na iPhone zatiaľ nefunguje, takže naskenuj jeho kód fotoaparátom.',
        'no_peer_answered_camera_off' => 'V tejto sieti na tento kód nič neodpovedalo. Vyhľadanie druhého zariadenia v sieti na iPhone zatiaľ nefunguje a prístup k fotoaparátu je vypnutý — povoľ preto fotoaparát pre Beatrax v nastaveniach zariadenia a naskenuj kód z druhého zariadenia.',
        'rate_limited' => 'Príliš veľa pokusov. Počkaj minútu a skús to znova.',
        'identity_locked' => 'Identita tvojho zariadenia je zamknutá. Odomkni aplikáciu a skús to znova.',
        'identity_needs_lock' => 'Najprv nastavte zámok aplikácie — chráni identitu vášho zariadenia.',
        'safety_number_changed' => 'Druhé zariadenie sa počas porovnávania zmenilo. Skôr než potvrdíš, skontroluj slová nižšie znova.',
    ],
];
