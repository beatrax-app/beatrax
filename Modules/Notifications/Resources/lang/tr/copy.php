<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'İçe aktarma tamamlandı',
        'receipts' => 'Yeni fişler bulundu',
        'drift' => 'Düzenli bir harcama değişti',
        'forecast' => 'Yaklaşan nakit akışı açığı',
        'budget_nudge' => 'Bütçe neredeyse tükendi',
        'savings_prompt' => 'Daha ucuz bir plan var',
        'ics_statement_ready' => 'Yeni ICS ekstresi hazır',
        'payment_reminder_confident' => 'Ödeme günü :day',
        'payment_reminder_hedged' => 'Ödeme günü :day civarı',
        'position_digest_daily' => 'Günlük durumun',
        'position_digest_weekly' => 'Haftalık durumun',
    ],

    'body' => [
        'budget_nudge' => ':category — :budget bütçesinin :spent kadarı harcandı.',
        'receipts_matched' => 'E-postandan :count fiş eşleştirildi.',
        'import_finished' => ':count işlem içe aktarıldı.',
        'drift' => 'Düzenli bir harcama :delta :currency :direction.',
        'forecast' => 'Öngörülen bakiyen önümüzdeki 30 gün içinde sıfırın altına iniyor.',
        'ics_statement_ready' => "Bunu ICS portalından indir ve bu kartın harcamalarını güncel tutmak için Beatrax'a bırak.",
        'payment_reminder_hedged' => ':name — :day civarı bekleniyor, :amount.',
        'payment_reminder_confident' => ':name — :day (:date) ödenecek, :amount.',
        'savings_prompt' => ':message (:monthly/ay)',
    ],

    'drift_direction' => [
        'up' => 'arttı',
        'down' => 'azaldı',
    ],

    'digest' => [
        'nothing_notable' => 'Dikkatini gerektiren bir şey yok.',
        'flow' => 'Giren :in, çıkan :out, net :net.',
        'over_budget' => 'Şu ana kadar bütçenin :amount üzerinde.',
        'payments_due' => 'Bu dönemde :count ödeme var.',
        'shortfall' => 'Yaklaşan bir nakit akışı açığı var.',
    ],
];
