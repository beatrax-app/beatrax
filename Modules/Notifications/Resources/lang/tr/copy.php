<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'İçe aktarma tamamlandı',
        'receipts' => 'Yeni fişler bulundu',
        'manual_entry' => 'Kasa defteri güncellendi',
        'migration_finished' => 'Taşıma tamamlandı',
        'drift' => 'Düzenli bir harcama değişti',
        'forecast' => 'Yaklaşan nakit akışı açığı',
        'budget_nudge' => 'Bütçe neredeyse tükendi',
        'budget_nudge_spent' => 'Bütçe tükendi',
        'budget_nudge_over' => 'Bütçe aşıldı',
        'savings_prompt' => 'Tasarruf edebileceğin bir yer',
        'ics_statement_ready' => 'Yeni ICS ekstresi hazır',
        'payment_reminder_confident' => 'Ödeme günü :day (:date)',
        'payment_reminder_hedged' => 'Ödeme günü :day (:date) civarı',
        'position_digest_daily' => 'Günlük durumun',
        'position_digest_weekly' => 'Haftalık durumun',
    ],

    'body' => [
        'budget_nudge' => ':category — :budget bütçesinin :spent kadarı harcandı.',
        'receipts_matched' => 'E-postandan :count fiş eşleştirildi.',
        'import_finished' => ':count işlem içe aktarıldı.',
        'manual_entry' => ':count kayıt elle eklendi.',
        'migration_finished' => 'Bütçen taşındı, :count işlem dahil.',
        'drift' => 'Düzenli bir harcama :amount :direction.',
        'forecast' => 'Öngörülen bakiyen :date tarihinde sıfırın altına iniyor.',
        'forecast_buffer' => 'Öngörülen bakiyen :date tarihinde :buffer tamponunun altına iniyor.',
        'ics_statement_ready' => "Bunu ICS portalından indir ve bu kartın harcamalarını güncel tutmak için Beatrax'a bırak.",
        'payment_reminder_hedged' => ':name — :day (:date) civarı bekleniyor, :amount.',
        'payment_reminder_confident' => ':name — :day (:date) ödenecek, :amount.',
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
