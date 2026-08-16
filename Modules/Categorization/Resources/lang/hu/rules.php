<?php

declare(strict_types=1);

return [
    'page_title' => 'Szabályok',
    'heading' => 'Szabályok',
    'intro' => 'Kategorizáld a tranzakciókat már importáláskor. A szabályok minden forrásra vonatkoznak — bank, kártya, PayPal és e-mailes bizonylatok.',

    'reapply' => 'Szabályok újraalkalmazása az előzményekre',
    'reapplying' => 'Újraalkalmazás…',
    'new_rule' => 'Új szabály',

    'reapply_progress_lead' => 'Szabályok újraalkalmazása…',
    'reapply_progress_of' => '/',
    'reapply_progress_trail' => 'tranzakció ellenőrizve',

    'empty_heading' => 'Még nincsenek szabályok',
    'empty_body' => 'A szabályok több feltétel alapján illeszkednek a tranzakciókra, és automatikusan alkalmazzák a kategória-, partner-, megjegyzés- és adócímke-változtatásokat — importáláskor, és bármikor, amikor újraalkalmazod őket a meglévő előzményeidre.',
    'empty_cta' => 'Hozd létre az első szabályodat',

    'col_priority' => 'Prioritás',
    'col_conditions' => 'Feltételek',
    'col_actions' => 'Műveletek',
    'col_hits' => 'Találatok',
    'col_created' => 'Létrehozva',
    'col_row_actions' => 'Műveletek',

    'more_conditions' => '+:count további',

    'delete_confirm' => 'Törlöd?',
    'delete_yes' => 'Igen, törlöm',
    'cancel' => 'Mégse',
    'edit' => 'Szerkesztés',
    'delete' => 'Törlés',
    'edit_aria' => 'Szabály szerkesztése (prioritás: :priority)',
    'delete_aria' => 'Szabály törlése (prioritás: :priority)',

    'footer_note' => 'A szabályok és a kereskedői előzmények együtt működnek. Egy szabály törlése nem törli azt, amit a Beatrax a korábbi kategorizálásokból tanult — a következő import az előzmények alapján továbbra is ugyanazt a kategóriát javasolhatja.',

    'chip_category' => 'Kategória: :path',
    'chip_counterparty' => 'Partner: :path',
    'chip_note' => 'Megjegyzés',
    'chip_tax_tag' => 'Adócímke',

    'flash_deleted' => 'Szabály törölve.',
    'flash_not_found' => 'A szabály nem található (lehet, hogy egy másik lapon törölték).',
    'flash_saved' => 'Szabály mentve.',
    'flash_reapplying' => 'Szabályok újraalkalmazása az előzményeidre…',
    'summary_no_changes' => 'Nincs változás — az előzményeid már megfelelnek a szabályaidnak.',
    'summary_updated' => ':fields mező frissítve :transactions tranzakcióban.',
    'summary_reconciled_skipped' => ':count egyeztetett tranzakció kimaradt.',
];
