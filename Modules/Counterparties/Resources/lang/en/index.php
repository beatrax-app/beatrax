<?php

declare(strict_types=1);

return [
    'page_title' => 'Counterparties',
    'heading' => 'Counterparties',

    'entities' => ':count entity|:count entities',
    'need_identification' => ':count need identification',

    'search_placeholder' => 'Search by name, alias, or IBAN…',
    'search_aria' => 'Search counterparties',
    'sort' => 'Sort: Total 12mo ↓',

    'view_mode' => 'View mode',
    'view_cards' => 'Cards',
    'view_list' => 'List',

    'filter_aria' => 'Filter by type',
    'chips' => [
        'all' => 'All',
        'merchant' => 'Merchants',
        'personal' => 'Personal',
        'bank' => 'Banks',
        'government' => 'Government',
        'self' => 'Self',
        'unknown' => 'Unknown',
    ],

    'empty_heading' => 'No counterparties yet',
    'empty_body' => 'Counterparties appear here automatically as you import transactions. Import a statement to get started.',
    'empty_cta' => 'Import a statement →',

    'self_routing' => 'Routing only',
    'self_no_flow' => 'no spend / no income',
    'self_open' => 'Open account →',

    'label_this' => 'Label this counterparty',

    'stat_12mo' => '12 mo',
    'stat_net_received' => 'Net received',
    'stat_avg_mo' => 'Avg / mo',
    'sparkline_aria' => '12-month activity sparkline',

    'table_name' => 'Name',
    'table_type' => 'Type',
    'table_12mo' => '12 mo',
    'table_avg' => 'Avg / mo',
];
