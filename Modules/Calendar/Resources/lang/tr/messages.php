<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Takvim',
        'subtitle' => 'Yaklaşan ödemeler ve öngörülen günlük bakiyen.',
    ],

    'summary' => [
        'computing' => 'Tahmin güncelleniyor…',
        'risk' => 'Bakiye :count gün :zero altına iniyor — ilki: :date.',
    ],

    'toolbar' => [
        'prev_month' => 'Önceki ay',
        'next_month' => 'Sonraki ay',
        'accounts' => 'Hesaplar',
        'popover_aria' => 'Hesap görüntüleme ayarları',
        'no_accounts' => 'Hesap bulunamadı.',
        'col_account' => 'Hesap',
        'col_entries' => 'Kayıtlar',
        'col_balance' => 'Bakiye',
        'show_entries_aria' => ':name kayıtlarını göster',
        'count_balance_aria' => ':name hesabını bakiyeye dahil et',
    ],

    'empty' => [
        'heading' => 'Yaklaşan ödeme yok',
        'body' => 'Öngörülen ödemelerini takvimde görmek için bir hesap bağla ya da bir düzenli seriyi onayla.',
        'review' => 'Düzenli işlemleri gözden geçir →',
    ],

    'weekdays' => [
        'mon' => 'Pzt',
        'tue' => 'Sal',
        'wed' => 'Çar',
        'thu' => 'Per',
        'fri' => 'Cum',
        'sat' => 'Cmt',
        'sun' => 'Paz',
    ],

    'grid' => [
        'aria' => ':month takvimi',
    ],

    'cell' => [
        'entry' => 'kayıt',
        'aria' => ':date: :count :entries',
        'aria_balance_negative' => ', öngörülen bakiye eksi :amount',
        'aria_balance_positive' => ', öngörülen bakiye :amount',
        'overflow' => '+:count daha',
        'paid' => 'Ödendi',
        'missed' => 'Beklendi — bulunamadı',
    ],

    'entry' => [
        'booked_unnamed' => 'Kaydedilmiş ödeme',
    ],

    'balance' => [
        'not_counted' => '· :list sayılmıyor — oradaki ödemeler bakiyeyi değiştirmez',
    ],

    'panel' => [
        'aria' => 'Gün ayrıntı paneli',
        'close' => 'Gün panelini kapat',
        'close_caption' => 'Kapat',
        'start_of_day' => 'Gün başı',
        'no_payments' => 'Bu gün için ödeme yok.',
        'date_approximate' => '~ tarih yaklaşık',
        'series' => '↗ seri',
        'counterparty' => '↗ karşı taraf',
        'transaction' => '↗ işlem',
        'end_of_day' => 'Gün sonu',
    ],
];
