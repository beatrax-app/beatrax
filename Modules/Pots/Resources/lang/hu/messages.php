<?php

declare(strict_types=1);

return [
    'page_title' => 'Perselyek · Beatrax',
    'heading' => 'Perselyek',
    'subtitle' => 'Virtuális részegyenlegek, a valós számlaegyenlegből elkülönítve.',
    'add_pot' => 'Persely hozzáadása',

    'pot_fallback' => 'persely',

    'empty' => [
        'heading' => 'Még nincs persely',
        'body' => 'Hozz létre virtuális részegyenlegeket bármelyik számládon belül, hogy valódi banki átutalás nélkül rendszerezd a pénzed.',
        'cta' => 'Add hozzá az első perselyed',
        'no_accounts_cta' => 'Kivonat importálása',
    ],

    'common' => [
        'cancel' => 'Mégse',
        'amount' => 'Összeg',
        'note_optional' => 'Megjegyzés (opcionális)',
    ],

    'actions' => [
        'fund' => 'Feltöltés',
        'move' => 'Átmozgatás',
        'edit' => 'Szerkesztés',
        'withdraw' => 'Kivétel',
        'archive' => 'Archiválás',
        'restore' => 'Visszaállítás',
    ],

    'recon' => [
        'over_allocated' => 'A perselyek :amount összeggel meghaladják a valós egyenleget — rendezd el',
        'real_balance' => 'Valós egyenleg:',
        'allocated' => 'Kiosztva:',
        'unallocated' => 'Kiosztatlan:',
    ],

    'chip' => [
        'goal' => 'Cél:',
        'goal_name_fallback' => 'Cél',
        'category_fallback' => 'Kategória',
    ],

    'coverage' => [
        'spent' => 'elköltve',
        'in_pot' => 'a perselyben',
    ],

    'archive_confirm' => 'Archiválod ezt a perselyt? A(z) :amount egyenleg visszakerül a kiosztatlan keretbe.',
    'confirm_archive_aria' => 'A(z) :name archiválásának megerősítése',
    'more_actions_aria' => 'További műveletek: :name',

    'history' => [
        'show' => 'Előzmények megjelenítése ↓',
        'hide' => 'Előzmények elrejtése ↑',
        'truncated' => 'Legutóbbi mozgások: :shown / :count',
    ],

    'movement' => [
        'fund' => 'Feltöltés',
        'withdraw' => 'Kivétel',
        'moved_from' => 'Áthelyezve innen: :name',
        'moved_to' => 'Áthelyezve ide: :name',
        'unreadable' => 'A Beatrax egy újabb verziója rögzítette',
        'released_on_archive' => 'Felszabadítva archiváláskor',
    ],

    'archived' => [
        'toggle' => 'Archivált persely (:count)|Archivált perselyek (:count)',
        'badge' => 'Archiválva',
    ],

    'form' => [
        'create_title' => 'Persely létrehozása',
        'edit_title' => 'Persely szerkesztése',
        'create_subtitle' => 'Nevezz el egy virtuális részegyenleget egy számlán belül.',
        'edit_subtitle' => 'Módosítsd a persely nevét vagy kapcsolatát.',
        'name' => 'Név',
        'name_placeholder' => 'pl. Nyaralási keret',
        'account' => 'Számla',
        'select_account' => 'Válassz számlát',
        'initial_amount' => 'Kezdő összeg (opcionális)',
        'initial_amount_help' => 'Az összeg a kiosztatlan keretből kerül levonásra. Hagyd üresen az üres perselyhez.',
        'link_to' => 'Kapcsolás ehhez (opcionális)',
        'link_goal' => 'Cél',
        'link_none' => 'Nincs',
        'select_goal' => 'Válassz célt',
        'save_pot' => 'Persely mentése',
        'save_changes' => 'Módosítások mentése',
    ],

    'fund' => [
        'title' => 'Persely feltöltése',
        'heading' => 'A(z) :name feltöltése',
        'submit' => 'Persely feltöltése',
        'note_placeholder' => 'pl. Havi megtakarítás',
        'available' => 'Kiosztható: :amount (kiosztatlan)',
    ],

    'move' => [
        'title' => 'Pénz átmozgatása',
        'heading' => 'Átmozgatás innen: :name',
        'to' => 'Átmozgatás ide',
        'select_pot' => 'Válassz perselyt',
        'no_others_short' => 'Nincs másik persely',
        'no_others' => 'Ezen a számlán nincs másik persely',
        'submit' => 'Pénz átmozgatása',
        'note_placeholder' => 'pl. Átvezetés a nyaralásra',
    ],

    'withdraw' => [
        'heading' => 'Kivét innen: :name',
        'note_placeholder' => 'pl. Kivét',
    ],

    'available_in' => 'Elérhető itt: :name: :amount',

    'errors' => [
        'enter_name' => 'Adj nevet ennek a perselynek.',
        'select_account' => 'Válassz számlát ehhez a perselyhez.',
        'amount_exceeds_unallocated_available' => 'Az összeg meghaladja a kiosztatlan egyenleget (:amount érhető el).',
        'amount_exceeds_pot_balance' => 'Az összeg meghaladja a(z) :name egyenlegét (:amount érhető el).',
        'generic' => 'A kasszát nem sikerült menteni. Ellenőrizze a mezőket, és próbálja újra.',
        'amount_invalid' => 'Adjon meg nullánál nagyobb összeget.',
        'goal_already_linked' => 'Ehhez a célhoz már tartozik aktív kassza. Előbb archiválja.',
        'account_cannot_hold_pots' => 'A persely olyan számlát igényel, amelyen pénz van. Válassz másik számlát.',
        'select_target_pot' => 'Válassz perselyt, amelybe átmozgatod.',
        'move_target_missing' => 'Ez a persely már nem érhető el. Válassz másikat.',
        'move_same_pot' => 'Persely nem mozgathat pénzt önmagába. Válassz másik perselyt.',
        'move_cross_account' => 'A perselyek csak egy számlán belül cserélnek pénzt, és a(z) :name a(z) :account számlán van.',
        'pot_missing' => 'Ez a persely már nem érhető el.',
        'operation_failed' => 'Nem ment át. Nem mozgott pénz — próbáld újra.',
    ],

    'toast' => [
        'pot_created' => 'Persely létrehozva.',
        'pot_updated' => 'Persely frissítve.',
        'pot_funded' => 'Persely feltöltve.',
        'withdrawn' => 'Kivéve a perselyből.',
        'funds_moved' => 'A pénz átmozgatva.',
        'pot_archived' => 'Persely archiválva.',
        'pot_restored' => 'Persely visszaállítva.',
    ],
];
