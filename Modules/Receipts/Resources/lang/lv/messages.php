<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'summa',
            'currency' => 'valūta',
            'description' => 'apraksts',
            'counterparty_name' => 'tirgotāja nosaukums',
            'default' => 'vērtība',
        ],
        'heading_cleaner' => 'E-pasta čekā lauks :field ir precīzāks',
        'heading_different' => 'E-pasta čekā lauks :field atšķiras',
        'title' => 'Čeks un konta izraksts nesakrīt.',
        'body' => ':heading — čekā (“:receipt”), konta izrakstā (“:statement”). Vai turpmākajos konfliktos Beatrax dot priekšroku čekiem?',
        'use_receipt' => 'Izmantot čeku',
        'keep_statement' => 'Paturēt konta izrakstu',
        'heading_restated' => 'Vēlākā konta izrakstā lauks :field atšķiras',
        'restated_title' => 'Banka pārrēķināja šo darījumu.',
        'restated_body' => ':heading — vēlākajā izrakstā (“:incoming”), jau saglabātajā rindā (“:stored”). Vai turpmākajās neatbilstībās Beatrax dot priekšroku jaunajai vērtībai?',
        'use_restated' => 'Izmantot jauno vērtību',
        'keep_stored' => 'Paturēt saglabāto vērtību',
    ],
];
