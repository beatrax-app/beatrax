<?php

declare(strict_types=1);

return [
    'heading' => 'Bir eşleşme öner',
    'intro' => 'Öneriyi taslak PR olarak gönderebilmen için tarayıcında GitHub açılır. Adın ve e-posta adresin bu cihazdan asla çıkmaz.',

    'pattern' => 'Desen',
    'name' => 'Anlaşılır ad',
    'name_placeholder' => 'ör. Albert Heijn',
    'category' => 'Kategori (isteğe bağlı)',
    'category_placeholder' => 'ör. Market',
    'region' => 'Bölge',

    'regions' => [
        'other' => 'Diğer',
    ],

    'yaml_preview' => 'YAML önizlemesi',

    'cancel' => 'İptal',
    'submit' => 'Taslak PR olarak gönder',

    'toast' => 'Öneri tarayıcında açıldı.',

    'errors' => [
        'pattern_required' => 'Desen zorunludur.',
        'name_required' => 'Ad zorunludur.',
        'browser_refused' => 'Tarayıcın açılamadı, bu yüzden hiçbir şey gönderilmedi ve hiçbir şey bu cihazdan çıkmadı. Tekrar dene ya da yukarıdaki YAML önizlemesini kendin bir pull request içine yapıştır.',
    ],
];
