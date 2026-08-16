<?php

declare(strict_types=1);

return [
    'title' => 'Sestavy',
    'page_title' => 'Sestavy · Beatrax',
    'saved_report' => 'uložená sestava|uložené sestavy|uložených sestav',
    'pinned_count' => 'připnuto',
    'dismiss' => 'Zamítnout',

    'build_new' => 'Vytvořit novou sestavu',
    'view_mode_aria' => 'Režim zobrazení',
    'cards' => 'Karty',
    'list' => 'Seznam',

    'empty' => [
        'heading' => 'Zatím žádné uložené sestavy',
        'body' => 'Vytvoř si jednu níž a ulož ji, ať se ti tady objeví.',
        'cta' => 'Vytvoř svou první sestavu →',
    ],

    'pin' => [
        'pinned_aria' => 'Připnuto — odepnout z Přehledu',
        'pin_aria' => 'Připnout — připnout do Přehledu',
        'pinned_title' => 'Připnuto',
        'pin_title' => 'Připnout do Přehledu',
        'pinned_label' => 'Připnuto',
        'pin_label' => 'Připnout',
    ],

    'open' => 'Otevřít',
    'edit' => 'Upravit',

    'delete_confirm' => 'Smazat „:name“?',
    'delete_report' => 'Smazat sestavu',
    'cancel' => 'Zrušit',
    'delete' => 'Smazat',
    'delete_aria' => 'Smazat: :name',

    'col' => [
        'name' => 'Název',
        'summary' => 'Souhrn',
        'pinned' => 'Připnuto',
        'actions' => 'Akce',
    ],

    'flash' => [
        'not_found' => 'Sestava nenalezena (mohla být smazána na jiné kartě).',
        'deleted' => 'Sestava smazána.',
    ],
    'pin_cap' => 'Připnout můžeš nejvýš 3 sestavy. Jednu odepni a tuhle přidáš.',

    'summary' => [
        'metric' => [
            'spend' => 'Výdaje',
            'income' => 'Příjmy',
            'net' => 'Netto',
            'net_worth' => 'Čisté jmění',
            'fallback' => 'Částka',
        ],
        'dimension' => [
            'category' => 'kategorie',
            'time_bucket' => 'časový úsek',
            'counterparty' => 'protistrana',
            'account' => 'účet',
            'fallback' => 'kategorie',
        ],
        'period' => [
            'this_month' => 'Tento měsíc',
            'last_3_months' => 'Poslední 3 měsíce',
            'last_6_months' => 'Posledních 6 měsíců',
            'last_12_months' => 'Posledních 12 měsíců',
            'ytd' => 'Od začátku roku',
            'this_year' => 'Tento rok',
            'custom' => 'Vlastní rozsah',
        ],
        'with_dimension' => ':metric · podle: :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
