<?php

declare(strict_types=1);

return [
    'title' => 'Zostavy',
    'page_title' => 'Zostavy · Beatrax',
    'saved_report' => 'uložená zostava|uložené zostavy|uložených zostáv',
    'pinned_count' => 'pripnutých',
    'dismiss' => 'Zamietnuť',

    'build_new' => 'Vytvoriť novú zostavu',
    'view_mode_aria' => 'Režim zobrazenia',
    'cards' => 'Karty',
    'list' => 'Zoznam',

    'empty' => [
        'heading' => 'Zatiaľ žiadne uložené zostavy',
        'body' => 'Vytvor si jednu nižšie a ulož ju, aby sa tu zobrazila.',
        'cta' => 'Vytvor svoju prvú zostavu →',
    ],

    'pin' => [
        'pinned_aria' => 'Pripnuté — odopnúť z Prehľadu',
        'pin_aria' => 'Pripnúť — pripnúť do Prehľadu',
        'pinned_title' => 'Pripnuté',
        'pin_title' => 'Pripnúť do Prehľadu',
        'pinned_label' => 'Pripnuté',
        'pin_label' => 'Pripnúť',
    ],

    'open' => 'Otvoriť',
    'edit' => 'Upraviť',

    'delete_confirm' => 'Zmazať „:name“?',
    'delete_report' => 'Zmazať zostavu',
    'cancel' => 'Zrušiť',
    'delete' => 'Zmazať',
    'delete_aria' => 'Zmazať: :name',

    'col' => [
        'name' => 'Názov',
        'summary' => 'Súhrn',
        'pinned' => 'Pripnuté',
        'actions' => 'Akcie',
    ],

    'flash' => [
        'not_found' => 'Zostava sa nenašla (mohla byť zmazaná na inej karte).',
        'deleted' => 'Zostava zmazaná.',
    ],
    'pin_cap' => 'Pripnúť môžeš najviac 3 zostavy. Ak chceš pridať túto, jednu odopni.',

    'summary' => [
        'metric' => [
            'spend' => 'Výdavky',
            'income' => 'Príjmy',
            'net' => 'Netto',
            'net_worth' => 'Čisté imanie',
            'fallback' => 'Suma',
        ],
        'dimension' => [
            'category' => 'kategória',
            'time_bucket' => 'časové obdobie',
            'counterparty' => 'protistrana',
            'account' => 'účet',
            'fallback' => 'kategória',
        ],
        'period' => [
            'this_month' => 'Tento mesiac',
            'last_3_months' => 'Posledné 3 mesiace',
            'last_6_months' => 'Posledných 6 mesiacov',
            'last_12_months' => 'Posledných 12 mesiacov',
            'ytd' => 'Od začiatku roka',
            'this_year' => 'Tento rok',
            'custom' => 'Vlastný rozsah',
        ],
        'with_dimension' => ':metric · podľa: :dimension · :period',
        'without_dimension' => ':metric · :period',
    ],
];
