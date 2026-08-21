<?php

declare(strict_types=1);

return [
    'title' => 'Jelentések',
    'page_title' => 'Jelentések · Beatrax',
    'saved_report' => '{0} :count mentett jelentés|[1,*] :count mentett jelentés',
    'pinned_count' => 'rögzítve',
    'dismiss' => 'Elvetés',

    'build_new' => 'Új jelentés összeállítása',
    'view_mode_aria' => 'Nézetmód',
    'cards' => 'Kártyák',
    'list' => 'Lista',

    'empty' => [
        'heading' => 'Még nincs mentett jelentés',
        'body' => 'Állíts össze egyet alább, és mentsd el, hogy itt megjelenjen.',
        'cta' => 'Állítsd össze az első jelentésed →',
    ],

    'pin' => [
        'pinned_aria' => 'Rögzítve — levétel az irányítópultról',
        'pin_aria' => 'Rögzítés — kitűzés az irányítópultra',
        'pinned_title' => 'Rögzítve',
        'pin_title' => 'Rögzítés az irányítópultra',
        'pinned_label' => 'Rögzítve',
        'pin_label' => 'Rögzítés',
    ],

    'open' => 'Megnyitás',
    'edit' => 'Szerkesztés',

    'delete_confirm' => 'Törlöd a következőt: „:name”?',
    'delete_report' => 'Jelentés törlése',
    'cancel' => 'Mégse',
    'delete' => 'Törlés',
    'delete_aria' => 'A(z) :name törlése',

    'col' => [
        'name' => 'Név',
        'summary' => 'Összefoglaló',
        'pinned' => 'Rögzítve',
        'actions' => 'Műveletek',
    ],

    'flash' => [
        'not_found' => 'A jelentés nem található (lehet, hogy egy másik lapon törölték).',
        'deleted' => 'Jelentés törölve.',
    ],
    'pin_cap' => 'Legfeljebb 3 jelentést rögzíthetsz. Vegyél le egyet, hogy ezt hozzáadhasd.',

    'summary' => [
        'metric' => [
            'spend' => 'Költés',
            'income' => 'Bevétel',
            'net' => 'Nettó',
            'net_worth' => 'Nettó vagyon',
            'fallback' => 'Összeg',
        ],
        'dimension' => [
            'category' => 'kategória',
            'time_bucket' => 'időszakos bontás',
            'counterparty' => 'partner',
            'account' => 'számla',
            'fallback' => 'kategória',
        ],
        'period' => [
            'this_month' => 'Ez a hónap',
            'last_3_months' => 'Elmúlt 3 hónap',
            'last_6_months' => 'Elmúlt 6 hónap',
            'last_12_months' => 'Elmúlt 12 hónap',
            'ytd' => 'Év eleje óta',
            'this_year' => 'Ez az év',
            'custom' => 'Egyéni tartomány',
        ],
        'with_dimension' => ':metric · :dimension szerint · :period',
        'without_dimension' => ':metric · :period',
    ],
];
