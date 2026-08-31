<?php

declare(strict_types=1);

return [
    'page_title' => 'Panel',
    'subtitle' => 'Bu dönem bir bakışta.',

    'previous_period' => 'Önceki dönem',
    'today' => 'Bugün',
    'next_period' => 'Sonraki dönem',

    'totals_aria' => 'Bu dönemin toplamları',
    'totals_aria_currency' => 'Bu dönemin toplamları — :currency',
    'in' => 'Giren',
    'out' => 'Çıkan',
    'net' => 'Net',

    'status_tiles_aria' => 'Durum kartları',
    'email_scan_health' => 'E-posta tarama durumu — :count bağlı gelen kutusu',

    'top_spending' => 'En çok harcama',
    'no_expenses' => 'Henüz kategorilendirilmiş gider yok.',
    'top_spending_refunded' => 'Sıralama dışı — :amount geri geldi',

    'recent_transactions' => 'Son işlemler',
    'view_all' => 'Tümünü gör',
    'nothing_period' => 'Bu dönem için gösterilecek bir şey yok.',
    'th_date' => 'Tarih',
    'th_counterparty' => 'Karşı taraf',
    'th_category' => 'Kategori',
    'th_amount' => 'Tutar',
    'uncategorized' => 'Kategorisiz',

    'jump_to_records' => [
        'body' => 'Bu dönem için burada bir şey yok. En son işlemleriniz hâlâ burada.',
        'action' => ':period dönemini göster',
    ],

    'reauth' => [
        'title' => 'Bir gelen kutusunun yeniden bağlanması gerekiyor.',
        'body' => 'Bir veya daha fazla gelen kutusunun oturumu kapandı — yeniden bağlayana kadar Beatrax bunları tarayamaz.',
        'link' => 'Gelen kutularına git',
        'dismiss' => 'Kapat',
    ],

    'failed_chain' => [
        'title' => 'Zincir çözümlemesi başarısız oldu.',
        'body' => 'Bir veya daha fazla zincir çözümleme işi hata verdi.',
        'link' => 'Kuyruk denetleyicisini aç',
    ],
];
