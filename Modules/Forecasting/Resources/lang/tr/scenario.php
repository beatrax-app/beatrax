<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Senaryo düzenleyici — :name',
    'rename_aria' => 'Senaryoyu yeniden adlandır',
    'save' => 'Kaydet',
    'save_changes' => 'Değişiklikleri kaydet',
    'cancel' => 'İptal',
    'rename' => 'Yeniden adlandır',
    'confirm_delete' => 'Silmeyi onayla',
    'delete_scenario' => 'Senaryoyu sil',

    'mutations_count' => 'Değişiklikler (:count)',
    'no_mutations' => 'Henüz değişiklik yok. Bu senaryonun referansınla nasıl karşılaştırıldığını görmek için aşağıdan bir tane ekle.',
    'editing' => 'Düzenleniyor — :kind',
    'edit' => 'Düzenle',
    'remove' => 'Kaldır',

    'add_mutation' => '+ Değişiklik ekle',
    'add_to_scenario' => 'Senaryoya ekle',
    'pick_kind' => 'Bir değişiklik türü seç:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Bir seriyi iptal et',
            'desc' => 'Onaylanmış bir serinin öngörülen tüm tekrarlarını çıkarır.',
        ],
        'add_one_off' => [
            'title' => 'Tek seferlik harcama veya gelir ekle',
            'desc' => 'Belirli bir tarihte tek bir varsayımsal olay.',
        ],
        'add_recurring' => [
            'title' => 'Düzenli seri ekle',
            'desc' => 'Varsayımsal yeni bir abonelik veya gelir akışı.',
        ],
        'change_series_amount' => [
            'title' => 'Seri tutarını değiştir',
            'desc' => 'Mevcut bir seride fiyat artışını veya düşüşünü modelle.',
        ],
        'shift_series_date' => [
            'title' => 'Seri tarihini kaydır',
            'desc' => 'Bir sonraki veya sonraki tüm tekrarları ileri taşır.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'İptal edilecek seri',
        'pick_series' => '— bir seri seç —',
        'date' => 'Tarih',
        'amount' => 'Tutar',
        'currency' => 'Para birimi',
        'direction' => 'Yön',
        'expense_long' => 'Gider (çıkan para)',
        'income_long' => 'Gelir (giren para)',
        'note' => 'Not (isteğe bağlı)',
        'start_date' => 'Başlangıç tarihi',
        'expense' => 'Gider',
        'income' => 'Gelir',
        'cadence' => 'Sıklık',
        'cadence_weekly' => 'Haftalık',
        'cadence_monthly' => 'Aylık',
        'cadence_quarterly' => 'Üç aylık',
        'cadence_yearly' => 'Yıllık',
        'series' => 'Seri',
        'new_amount' => 'Yeni tutar',
        'new_next_date' => 'Yeni sonraki tarih',
        'scope' => 'Kapsam',
        'scope_next' => 'Yalnızca bir sonraki tekrar',
        'scope_all' => 'Sonraki tüm tekrarlar',
    ],

    'whatif' => [
        'trigger' => 'Senaryo modelle',
        'menu_aria' => ':name için senaryo modelle',
        'model_cancellation' => 'İptali modelle',
        'model_amount_change' => 'Tutar değişikliğini modelle…',
        'amount_dialog_aria' => ':name için tutar değişikliğini modelle',
        'current_amount' => 'Güncel tutar',
        'new_amount' => 'Yeni tutar',
    ],

    'summary' => [
        'cancel' => ':name iptali',
        'series_fallback' => 'seri #:id',
        'one_off' => ':date tarihinde :amount :currency',
        'recurring' => ':date tarihinden itibaren :cadence :amount :currency',
        'change_amount' => ':name: yeni tutar :amount',
        'shift' => ':name: :scope :date tarihine kaydırılsın',
        'scope_all' => 'sonraki tümü',
        'scope_next' => 'sonraki',
    ],

    'toast' => [
        'created' => 'Senaryo ":name" oluşturuldu.',
        'deleted' => 'Senaryo silindi.',
        'renamed' => 'Senaryo yeniden adlandırıldı.',
        'mutation_added' => 'Değişiklik eklendi.',
        'mutation_updated' => 'Değişiklik güncellendi.',
        'mutation_removed' => 'Değişiklik kaldırıldı. Geri al',
    ],

    'errors' => [
        'name_empty' => 'Senaryo adı boş olamaz.',
        'name_too_long' => 'Senaryo adı en fazla :max karakter olmalıdır.',
        'name_taken' => 'Bu ada sahip bir senaryo zaten var.',
        'pick_kind_first' => 'Önce bir değişiklik türü seç.',
        'amount_positive' => 'Tutar pozitif bir sayı olmalıdır.',
    ],
];
