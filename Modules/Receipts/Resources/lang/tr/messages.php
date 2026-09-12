<?php

declare(strict_types=1);

return [
    'conflict' => [

        'field' => [
            'amount_minor' => 'tutar',
            'currency' => 'para birimi',
            'description' => 'açıklama',
            'counterparty_name' => 'işyeri adı',
            'default' => 'değer',
        ],
        'heading_cleaner' => 'Bir e-posta fişinde :field alanı daha temiz',
        'heading_different' => 'Bir e-posta fişi :field alanını farklı kaydediyor',
        'title' => 'Fiş ile hesap ekstresi uyuşmuyor.',
        'body' => ':heading (“:receipt”); ekstrede ise (“:statement”). Beatrax sonraki çakışmalarda fişleri tercih etsin mi?',
        'use_receipt' => 'Fişi kullan',
        'keep_statement' => 'Ekstreyi koru',
        'heading_restated' => 'Sonraki bir ekstre :field alanını farklı kaydediyor',
        'restated_title' => 'Banka bu işlemi düzeltti.',
        'restated_body' => ':heading (“:incoming”); kayıtlı satırda ise (“:stored”). Beatrax sonraki farklılıklarda yeni değeri tercih etsin mi?',
        'use_restated' => 'Yeni değeri kullan',
        'keep_stored' => 'Kayıtlı değeri koru',
    ],
];
