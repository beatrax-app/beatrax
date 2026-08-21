<?php

declare(strict_types=1);

return [
    'page' => [
        'title' => 'Hedefler',
        'subtitle' => 'Birikim hedeflerine doğru ilerlemeni izle.',
        'add_goal' => 'Hedef ekle',
    ],

    'empty' => [
        'heading' => 'Henüz hedef yok',
        'body' => 'Birikim ilerlemeni izlemeye başlamak için bir hedef tutarı ve tarihi belirle.',
        'add_first' => 'İlk hedefini ekle',
    ],

    'status' => [
        'overdue' => 'Gecikmiş',
        'reached' => 'Ulaşıldı',
        'completed' => 'Tamamlandı',
        'archived' => 'Arşivlendi',
    ],

    'row' => [
        'edit' => 'Düzenle',
    ],

    'progress' => [
        'aria' => ':name: %:pct tamamlandı',
    ],

    'card' => [
        'target_date' => 'Son tarih: :date',
    ],

    'projection' => [
        'target_reached' => 'Hedefe ulaşıldı',
        'add_contributions' => 'Tahmin görmek için katkı ekle',
        'not_enough_history' => 'Tarih tahmini için henüz yeterli geçmiş yok',
        'no_recent_contributions' => 'Tahmin yapılacak yakın tarihli katkı yok',
        'est' => 'Tahmini :date ·',
        'projection_note' => '(tahmin)',
        'projected' => 'Öngörülen: :date',
    ],

    'archive' => [
        'confirm_question' => 'Bu hedef arşivlensin mi?',
        'close' => 'Kapat',
        'confirm_aria' => ':name hedefinin arşivlenmesini onayla',
        'archive' => 'Arşivle',
    ],

    'actions' => [
        'more_aria' => ':name için diğer eylemler',
        'mark_complete' => 'Tamamlandı olarak işaretle',
        'archive' => 'Arşivle',
        'restore' => 'Geri yükle',
    ],

    'archived_disclosure' => 'Arşivlenmiş hedefler (:count)',

    'form' => [
        'title_edit' => 'Hedefi düzenle',
        'title_create' => 'Birikim hedefi oluştur',
        'subtitle_edit' => 'Adı, hedef tutarı, tarihi ya da bağlı kumbarayı güncelle.',
        'subtitle_create' => 'Birikim ilerlemeni izlemek için bir hedef tutarı ve tarihi belirle.',
        'name' => 'Ad',
        'name_placeholder' => 'ör. Acil durum fonu',
        'target_amount' => 'Hedef tutar (:currency)',
        'target_date' => 'Son tarih',
        'linked_pot' => 'Bağlı kumbara (isteğe bağlı)',
        'no_pot' => 'Kumbara yok — transfer takibini kullan',
        'linked_pot_help' => 'Bağlandığında kumbaranın bakiyesi bu hedefin ilerlemesini belirler.',
        'save_changes' => 'Değişiklikleri kaydet',
        'save_goal' => 'Hedefi kaydet',
        'close' => 'Kapat',
    ],

    'summary' => [
        'see_all' => 'Tümünü gör →',
        'no_goals' => 'Henüz hedef yok.',
        'add_first' => 'İlk hedefini ekle →',
    ],

    'notices' => [
        'goal_created' => 'Hedef oluşturuldu.',
        'goal_updated' => 'Hedef güncellendi.',
        'goal_marked_complete' => 'Hedef tamamlandı olarak işaretlendi.',
        'goal_archived' => 'Hedef arşivlendi.',
        'goal_restored' => 'Hedef geri yüklendi.',
    ],

    'errors' => [
        'name' => 'Hedefin için bir ad gir.',
        'date' => 'Bir son tarih seç.',
        'amount' => 'Sıfırdan büyük geçerli bir tutar gir.',
        'pot_linked_category' => 'Bu kumbara bir kategoriye bağlı. Önce Kumbaralar sayfasında bu bağlantıyı kaldır.',
    ],
];
