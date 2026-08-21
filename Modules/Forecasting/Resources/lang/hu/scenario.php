<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Forgatókönyv-szerkesztő — :name',
    'rename_aria' => 'Forgatókönyv átnevezése',
    'save' => 'Mentés',
    'save_changes' => 'Módosítások mentése',
    'cancel' => 'Mégse',
    'rename' => 'Átnevezés',
    'confirm_delete' => 'Törlés megerősítése',
    'delete_scenario' => 'Forgatókönyv törlése',
    'delete_confirm' => 'Törli ezt a forgatókönyvet?',

    'mutations_count' => 'Módosítások (:count)',
    'no_mutations' => 'Még nincs módosítás. Adj hozzá egyet alább, hogy lásd, hogyan viszonyul ez a forgatókönyv az alapesethez.',
    'editing' => 'Szerkesztés — :kind',
    'edit' => 'Szerkesztés',
    'remove' => 'Eltávolítás',

    'add_mutation' => '+ Módosítás hozzáadása',
    'add_to_scenario' => 'Hozzáadás a forgatókönyvhöz',
    'pick_kind' => 'Válassz módosítástípust:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Sorozat lemondása',
            'desc' => 'Egy jóváhagyott sorozat minden előrejelzett előfordulásának elhagyása.',
        ],
        'add_one_off' => [
            'title' => 'Egyszeri terhelés vagy jóváírás hozzáadása',
            'desc' => 'Egyetlen feltételezett esemény egy adott dátumon.',
        ],
        'add_recurring' => [
            'title' => 'Ismétlődő sorozat hozzáadása',
            'desc' => 'Egy feltételezett új előfizetés vagy bevételi forrás.',
        ],
        'change_series_amount' => [
            'title' => 'Sorozat összegének módosítása',
            'desc' => 'Áremelés vagy árcsökkenés modellezése egy meglévő sorozaton.',
        ],
        'shift_series_date' => [
            'title' => 'Sorozat dátumának eltolása',
            'desc' => 'A következő vagy az összes további előfordulás előbbre hozása.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Lemondandó sorozat',
        'pick_series' => '— válassz sorozatot —',
        'date' => 'Dátum',
        'amount' => 'Összeg',
        'currency' => 'Pénznem',
        'direction' => 'Irány',
        'expense_long' => 'Kiadás (kimenő pénz)',
        'income_long' => 'Bevétel (bejövő pénz)',
        'note' => 'Megjegyzés (opcionális)',
        'start_date' => 'Kezdő dátum',
        'expense' => 'Kiadás',
        'income' => 'Bevétel',
        'cadence' => 'Gyakoriság',
        'cadence_weekly' => 'Heti',
        'cadence_monthly' => 'Havi',
        'cadence_quarterly' => 'Negyedéves',
        'cadence_yearly' => 'Éves',
        'series' => 'Sorozat',
        'new_amount' => 'Új összeg',
        'new_next_date' => 'Új következő dátum',
        'scope' => 'Hatókör',
        'scope_legend' => 'Mely előfordulások tolódjanak el',
        'scope_next' => 'Csak a következő előfordulás',
        'scope_all' => 'Az összes további előfordulás',
    ],

    'whatif' => [
        'trigger' => 'Mi lenne, ha…',
        'menu_aria' => 'Mi lenne, ha… modellezése ehhez: :name',
        'model_cancellation' => 'Lemondás modellezése',
        'model_amount_change' => 'Összegváltozás modellezése…',
        'amount_dialog_aria' => 'Összegváltozás modellezése ehhez: :name',
        'current_amount' => 'Jelenlegi összeg',
        'new_amount' => 'Új összeg',
    ],

    'series_name_fallback' => 'sorozat',

    'summary' => [
        'cancel' => 'A(z) :name lemondása',
        'series_fallback' => ':id. sorozat',
        'one_off' => ':amount :currency ekkor: :date',
        'recurring' => ':amount :currency :cadence ettől: :date',
        'change_amount' => ':name: új összeg :amount',
        'shift' => ':name: :scope eltolása ide: :date',
        'scope_all' => 'az összes további',
        'scope_next' => 'a következő',
    ],

    'toast' => [
        'created' => 'A(z) „:name” forgatókönyv létrehozva.',
        'deleted' => 'Forgatókönyv törölve.',
        'renamed' => 'Forgatókönyv átnevezve.',
        'mutation_added' => 'Módosítás hozzáadva.',
        'mutation_updated' => 'Módosítás frissítve.',
        'mutation_removed' => 'Módosítás eltávolítva. Visszavonás',
    ],

    'errors' => [
        'name_empty' => 'A forgatókönyv neve nem lehet üres.',
        'name_too_long' => 'A forgatókönyv neve legfeljebb :max karakter lehet.|A forgatókönyv neve legfeljebb :max karakter lehet.',
        'name_taken' => 'Ilyen nevű forgatókönyv már létezik.',
        'pick_kind_first' => 'Előbb válassz módosítástípust.',
        'amount_positive' => 'Az összegnek pozitív számnak kell lennie.',
    ],
];
