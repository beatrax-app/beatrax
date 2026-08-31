<?php

declare(strict_types=1);

return [
    'page_title' => 'Lánctippek',
    'heading' => 'Tippek',
    'back_to_review' => '← Vissza az áttekintési sorhoz',
    'subtitle' => 'Jelöltek, amelyeket egy párosító talált megfelelő partner nélkül. Egy elszámolási tipp magától eltűnik, amint megérkeznek a hiányzó terhelések; a többi addig marad, amíg itt el nem veted.',

    'empty_heading' => 'Nincs besorolandó tipp',
    'empty_body' => 'Ha egy illesztő olyan láncot talál, amelyet nem tudott automatikusan feloldani, az itt jelenik meg.',

    'no_counterparty' => '(nincs partner)',
    'unknown_account' => '(ismeretlen számla)',

    'dismiss' => 'Elvetés',
    'dismiss_aria' => 'A(z) :id tipp elvetése',
    'dismissed' => 'A tipp elvetve.',

    'kind' => [
        'ics_bulk_settle' => 'Csoportos iDEAL-elszámolás (tűréshatáron kívül)',
        'funded_by_card_hint' => 'Kártyáról fedezve (tipp)',
        'refund_of_hint' => 'Visszatérítés (tipp)',
    ],

    'evidence' => [
        'tolerance' => 'Tűrés: :tolerance',
        'tolerance_used' => [
            'amount_5eur' => 'a fix sávon belül',
            'percent_2' => 'a százalékos sávon belül',
            'exceeded' => 'a sávon kívül',
            'refund_after_close' => 'visszatérítés a lezárás után',
        ],
        'delta_overpaid' => ':amount túlfizetés',
        'delta_underpaid' => ':amount hiányzik',
        'delta_balanced' => 'Pontosan kiegyenlítve',
        'covered' => 'Lefedett tranzakciók: :count',
        'statement' => 'Kártyakivonat #:id',
        'card_last4' => ':last4 végű kártya',
        'original_reference' => 'Eredeti rendelési hivatkozás: :reference',
    ],
];
