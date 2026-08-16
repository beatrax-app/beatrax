<?php

declare(strict_types=1);

return [
    'heading' => 'Loglar',
    'subtitle' => 'Bugünkü Laravel log dosyasının canlı takibi; veriler hem yazılırken hem de akış sırasında çift katmanlı olarak maskelenir.',
    'truncate' => 'Boşalt',
    'truncate_confirm' => 'Bugünkü log dosyası boşaltılsın mı? Bu işlem geri alınamaz.',
    'truncate_title' => 'Bugünkü log dosyasını boşaltır (takipçi sorunsuz devam etsin diye inode korunur)',
    'filters_aria' => 'Log filtreleri',
    'severity_aria' => 'Önem düzeyi filtresi',
    'channel_placeholder' => 'Kanal filtresi…',
    'channel_aria' => 'Kanal filtresi',
    'contains_placeholder' => 'Görünenlerde ara…',
    'contains_aria' => 'İçerik filtresi',
    'pause' => 'Duraklat',
    'resume' => 'Devam et',
    'waiting' => 'Log satırları bekleniyor…',
    'copy' => 'Kopyala',
    'copy_title' => 'Kaydın tamamını kopyala',
    'copy_title_copied' => 'Kopyalandı',
    'copy_aria' => 'Log kaydını kopyala',
    'copy_aria_copied' => 'Panoya kopyalandı',
    'dismiss' => 'Kapat',
    'dismiss_title' => 'Görünümden gizler (log dosyasını değiştirmez)',
    'dismiss_aria' => 'Log kaydını görünümden gizle',
    'totals' => [
        'showing' => 'Gösterilen',
        'of' => '/',
        'received' => 'alınan (tampon sınırı 10k)',
        'lines_today' => 'satır bugün',
        'today' => 'bugün',
        'across' => 'toplam',
        'daily_files' => 'günlük dosyada',
    ],

    'status' => [
        'poll_interrupted' => 'Log yoklaması kesildi. Yeniden deneniyor…',
        'paused' => 'Duraklatıldı.',
        'copy_failed_prefix' => 'Kopyalama başarısız: ',
        'clipboard_unavailable' => 'pano kullanılamıyor',
    ],

    'toast' => [
        'truncated' => 'Log boşaltıldı — :size yer açıldı.',
        'nothing' => 'Boşaltılacak bir şey yok.',
    ],
];
