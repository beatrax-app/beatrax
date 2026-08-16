<?php

declare(strict_types=1);

return [
    'heading' => 'Gelen kutuları',
    'intro' => "Beatrax'ın fiş araması yapabilmesi için Gmail ve Microsoft 365 gelen kutularını bağla.",

    'connection_canceled' => 'Bağlantı iptal edildi.',
    'connection_failed' => 'Bağlantı tamamlanamadı.',

    'backfilling' => 'Geçmiş taranıyor',
    'messages_suffix' => 'mesaj',

    'connect_heading' => 'E-postanı bağla',
    'connect_body' => "PayPal, ICS Cards, Google Play ve diğer işyerlerinden gelen fişleri içe aktarmak için Beatrax'a bir veya daha fazla gelen kutun için salt okunur erişim ver.",
    'connect_gmail' => 'Gmail bağla',
    'connect_microsoft' => 'Microsoft 365 bağla',
    'readonly_note' => 'Beatrax yalnızca mesajları okur. Gelen kutunda hiçbir şey göndermez, etiketlemez, taşımaz veya silmez.',

    'month' => '1 ay',
    'months' => ':count ay',
    'not_scanned_yet' => 'henüz taranmadı',
    'last_scanned' => 'son tarama',
    'window_prefix' => 'Aralık:',
    'edit' => 'Düzenle',

    'badge' => [
        'idle' => 'Boşta',
        'backfilling' => 'Geçmiş taranıyor',
        'scanning' => 'Taranıyor',
        'rate_limited' => 'Hız sınırı',
        'needs_reauth' => 'Yeniden yetki gerekli',
        'error' => 'Hata',
    ],

    'retry_seconds' => ':nsn sonra yeniden denenecek',
    'retry_minutes' => ':ndk sonra yeniden denenecek',
    'retry_hours' => ':nsa sonra yeniden denenecek',

    'reconnect' => 'Yeniden bağlan',
    'scan_now' => 'Şimdi tara',
    'scan_in_progress_title' => 'Tarama zaten sürüyor',

    'add_another' => 'Başka bir gelen kutusu ekle',
    'gmail_card_body' => "Beatrax'ın fiş araması yapabilmesi için bir Gmail hesabı bağla.",
    'microsoft_card_body' => "Beatrax'ın fiş araması yapabilmesi için bir Microsoft 365 veya Outlook.com hesabı bağla.",

    'discovered_heading' => 'Keşfedilen gönderenler',
    'discovered_body' => "Fiş gönderiyor gibi görünen ancak henüz bilinen fiş gönderenleri listende olmayan adresler. Beatrax'ın taramasını istediklerini ekle, kalanları yoksay.",
    'last_seen' => 'son görülme',
    'seen_times' => ':count kez görüldü',
    'add' => 'Ekle',
    'add_aria' => ':email adresini ekle',
    'dismiss' => 'Yoksay',
    'dismiss_aria' => ':email adresini yoksay',

    'toast' => [
        'scan_in_progress' => 'Tarama zaten sürüyor.',
        'scan_started' => 'Tarama başladı.',
        'sender_added' => 'Gönderen eklendi.',
        'sender_dismissed' => 'Gönderen yoksayıldı.',
    ],
];
