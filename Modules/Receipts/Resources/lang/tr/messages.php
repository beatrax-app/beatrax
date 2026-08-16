<?php

declare(strict_types=1);

return [
    'wizard' => [
        'intro' => 'Bir e-posta iletisi (.eml) veya posta kutusu arşivi (.mbox) bırak. Eşleştirici, PayPal fişlerini tanır ve bunları kanonik işlemler olarak gösterir; eşleşmeyen gönderenler ayıklama için denetim kaydında kalır.',
    ],

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
    ],
];
