<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Kategorisiz',
    'unavailable_category' => 'Kategori bu cihazda yok',
    'duplicate_path' => ':path (:number)',
    'status' => [
        'cleared' => 'Onaylandı',
        'uncleared' => 'Onaylanmadı',
        'reconciled' => 'Mutabakatlı',
    ],

    'badge' => [

        'reconciled_hint' => 'Mutabakatlı — durumu değiştirmek için önce mutabakatı geri al.',
        'toggle_aria' => ':label — değiştirmek için tıkla',
        // i18n-review: tr · toggle_aria_touch — the same line for a touch
        // screen; check the verb governs this case.
        'toggle_aria_touch' => ':label — değiştirmek için dokun',
    ],
];
