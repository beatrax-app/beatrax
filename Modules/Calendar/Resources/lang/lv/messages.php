<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Kalendārs',
        'subtitle' => 'Gaidāmie maksājumi un prognozētais dienas atlikums.',
    ],

    'summary' => [
        'computing' => 'Prognoze tiek atjaunināta…',
        'risk' => 'Atlikums noslīd zem 0 € :count dienās — pirmā: :date.|Atlikums noslīd zem 0 € :count dienā — pirmā: :date.|Atlikums noslīd zem 0 € :count dienās — pirmā: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Iepriekšējais mēnesis',
        'next_month' => 'Nākamais mēnesis',
        'accounts' => 'Konti',
        'popover_aria' => 'Kontu attēlošanas iestatījumi',
        'no_accounts' => 'Konti nav atrasti.',
        'col_account' => 'Konts',
        'col_entries' => 'Ieraksti',
        'col_balance' => 'Atlikums',
        'show_entries_aria' => 'Rādīt konta :name ierakstus',
        'count_balance_aria' => 'Ieskaitīt kontu :name atlikumā',
    ],

    'empty' => [
        'heading' => 'Nav gaidāmu maksājumu',
        'body' => 'Pievienojiet kontu vai apstipriniet regulāro maksājumu sēriju, lai kalendārā redzētu prognozētos maksājumus.',
        'review' => 'Pārskatīt regulāros maksājumus →',
    ],

    'weekdays' => [
        'mon' => 'Pr',
        'tue' => 'Ot',
        'wed' => 'Tr',
        'thu' => 'Ce',
        'fri' => 'Pk',
        'sat' => 'Se',
        'sun' => 'Sv',
    ],

    'grid' => [
        'aria' => ':month kalendārs',
    ],

    'cell' => [
        'entry' => 'ierakstu|ieraksts|ieraksti',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', prognozētais atlikums mīnus :amount',
        'aria_balance_positive' => ', prognozētais atlikums :amount',
        'overflow' => 'vēl +:count',
        'paid' => 'Samaksāts',
        'missed' => 'Gaidīts — nav atrasts',
    ],

    'entry' => [
        'booked_unnamed' => 'Iegrāmatots maksājums',
    ],

    'panel' => [
        'aria' => 'Dienas detaļu panelis',
        'close' => 'Aizvērt dienas paneli',
        'start_of_day' => 'Dienas sākums',
        'no_payments' => 'Šajā dienā nav maksājumu.',
        'date_approximate' => '~ aptuvens datums',
        'series' => '↗ sērija',
        'counterparty' => '↗ darījuma partneris',
        'transaction' => '↗ darījums',
        'end_of_day' => 'Dienas beigas',
    ],
];
