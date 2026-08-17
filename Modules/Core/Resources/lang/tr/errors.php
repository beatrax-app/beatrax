<?php

declare(strict_types=1);

return [
    'back' => 'Beatrax\'a dön',

    '404' => [
        'title' => 'Bu sayfa yok',
        'body' => 'Bağlantı eski olabilir ya da sayfanın adı değişmiş olabilir. Verilerinde bir sorun yok.',
    ],

    '419' => [
        'title' => 'Oturumun sona erdi',
        'body' => 'Sayfanın eskimesine yetecek kadar uzak kaldın. Beatrax\'ı yeniden aç ve devam et.',
    ],

    '500' => [
        'title' => 'Bir şeyler ters gitti',
        'body' => 'Sorun bu cihazın günlüğüne yazıldı. Verilerin değişmedi.',
    ],

    '503' => [
        'title' => 'Beatrax kısa süre kullanılamıyor',
        'body' => 'Bir güncelleme veya bakım tamamlanıyor. Birazdan yeniden dene.',
    ],
];
