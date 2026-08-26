<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Célok',
        'subtitle' => 'Kövesd nyomon a megtakarítási céljaid alakulását.',
        'add_goal' => 'Cél hozzáadása',
    ],

    'empty' => [
        'heading' => 'Még nincs cél',
        'body' => 'Adj meg egy célösszeget és egy céldátumot, hogy nyomon követhesd a megtakarításod alakulását.',
        'add_first' => 'Add hozzá az első célod',
    ],

    'status' => [
        'overdue' => 'Lejárt',
        'reached' => 'Elérve',
        'completed' => 'Teljesítve',
        'archived' => 'Archiválva',
    ],

    'row' => [
        'edit' => 'Szerkesztés',
    ],

    'progress' => [
        'aria' => ':name: :pct% kész',
    ],

    'card' => [
        'target_date' => 'Céldátum: :date',
    ],

    'projection' => [
        'target_reached' => 'Cél elérve',
        'closed_short' => 'Lezárva a cél elérése előtt',
        'add_contributions' => 'Adj hozzá befizetéseket az előrejelzéshez',
        'not_enough_history' => 'Még nincs elég előzmény a dátum előrejelzéséhez',
        'no_recent_contributions' => 'Nincs friss befizetés, amiből előre lehetne jelezni',
        'est' => 'Becsült: :date ·',
        'projection_note' => '(előrejelzés)',
        'projected' => 'Előrejelzés: :date',
    ],

    'archive' => [
        'confirm_question' => 'Archiválod ezt a célt?',
        'close' => 'Bezárás',
        'confirm_aria' => 'A(z) :name archiválásának megerősítése',
        'archive' => 'Archiválás',
    ],

    'actions' => [
        'more_aria' => 'További műveletek: :name',
        'mark_complete' => 'Megjelölés teljesítettként',
        'archive' => 'Archiválás',
        'restore' => 'Visszaállítás',
    ],

    'archived_disclosure' => 'Archivált célok (:count)',

    'form' => [
        'title_edit' => 'Cél szerkesztése',
        'title_create' => 'Megtakarítási cél létrehozása',
        'subtitle_edit' => 'Módosítsd a nevet, a célösszeget, a dátumot vagy a kapcsolt perselyt.',
        'subtitle_create' => 'Adj meg egy célösszeget és egy céldátumot a megtakarításod követéséhez.',
        'name' => 'Név',
        'name_placeholder' => 'pl. Vésztartalék',
        'target_amount' => 'Célösszeg (:currency)',
        'target_date' => 'Céldátum',
        'linked_pot' => 'Kapcsolt persely (opcionális)',
        'no_pot' => 'Nincs persely — átutalás-alapú követés',
        'linked_pot_help' => 'Ha kapcsolod, a persely egyenlege határozza meg a cél előrehaladását.',
        'save_changes' => 'Módosítások mentése',
        'save_goal' => 'Cél mentése',
        'close' => 'Bezárás',
    ],

    'summary' => [
        'see_all' => 'Összes megtekintése →',
        'no_goals' => 'Még nincs cél.',
        'add_first' => 'Add hozzá az első célod →',
    ],

    'notices' => [
        'goal_created' => 'Cél létrehozva.',
        'goal_updated' => 'Cél frissítve.',
        'goal_marked_complete' => 'A cél teljesítettként megjelölve.',
        'goal_archived' => 'Cél archiválva.',
        'goal_restored' => 'Cél visszaállítva.',
    ],

    'errors' => [
        'name' => 'Adj nevet a célnak.',
        'date' => 'Válassz céldátumot.',
        'amount' => 'Adj meg érvényes, nullánál nagyobb összeget.',
        'pot_linked_category' => 'Ez a persely egy kategóriához van kapcsolva. Előbb szüntesd meg a kapcsolatot a Perselyek oldalon.',
    ],
];
