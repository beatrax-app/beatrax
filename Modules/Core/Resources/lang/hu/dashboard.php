<?php

declare(strict_types=1);

return [
    'page_title' => 'Irányítópult',
    'subtitle' => 'Ez az időszak egy pillantásra.',

    'previous_period' => 'Előző időszak',
    'today' => 'Ma',
    'next_period' => 'Következő időszak',

    'totals_aria' => 'Az időszak összesítései',
    'totals_aria_currency' => 'Az időszak összesítései — :currency',
    'in' => 'Be',
    'out' => 'Ki',
    'net' => 'Nettó',

    'status_tiles_aria' => 'Állapotcsempék',
    'email_scan_health' => '{0} E-mail-vizsgálat állapota — nincs csatlakoztatott postafiók|[1,1] E-mail-vizsgálat állapota — :count csatlakoztatott postafiók|[2,*] E-mail-vizsgálat állapota — :count csatlakoztatott postafiók',

    'top_spending' => 'Legnagyobb kiadások',
    'no_expenses' => 'Még nincs kategorizált kiadás.',
    'top_spending_refunded' => 'Nincs rangsorolva — :amount visszajött',

    'recent_transactions' => 'Legutóbbi tranzakciók',
    'view_all' => 'Összes megtekintése',
    'nothing_period' => 'Ebben az időszakban nincs semmi.',
    'th_date' => 'Dátum',
    'th_counterparty' => 'Partner',
    'th_category' => 'Kategória',
    'th_amount' => 'Összeg',
    'uncategorized' => 'Kategorizálatlan',

    'jump_to_records' => [
        'body' => 'Ebben az időszakban nincs semmi. A legutóbbi tételei továbbra is megvannak.',
        'action' => 'Időszak mutatása: :period',
    ],

    'reauth' => [
        'title' => 'Egy postafiókot újra kell csatlakoztatni.',
        'body' => 'Egy vagy több postafiókból kiléptetett a rendszer — a Beatrax addig nem tudja vizsgálni őket, amíg újra nem csatlakoztatod.',
        'link' => 'Ugrás a postafiókokhoz',
        'dismiss' => 'Elvetés',
    ],

    'failed_chain' => [
        'title' => 'A láncfeloldás sikertelen.',
        'body' => 'Egy vagy több láncfeloldási feladat hibába ütközött.',
        'link' => 'Várólista-vizsgáló megnyitása',
    ],
];
