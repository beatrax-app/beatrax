<?php

declare(strict_types=1);

return [
    'eyebrow' => 'Gözden geçir ve kaydet',
    'h1' => 'Bulduğumuz her şeyi gözden geçir',

    'lede_counts' => ':transactions — :sources.',
    'source' => ':count kaynaktan',
    'lede_confirm' => 'Açılış bakiyelerini onayla, sonra hepsini kaydet.',

    'empty' => 'Gözden geçirilecek bir şey yok. İşlemlerini burada görmek için önceki adımlarda bir hesap ekstresi bırak.',

    'sb_eyebrow_label' => '🧮 AÇILIŞ BAKİYELERİ ·',
    'account_detected' => ':count HESAP ALGILANDI',
    'sb_lede' => 'Her hesabın açılış bakiyesini algıladık. Kaydetmeden önce onayla veya düzenle.',

    'txn' => ':count işlem',
    'to_commit' => 'kaydedilecek ·',
    'already_imported' => ':count zaten içe aktarıldı',
    'commit_committing' => 'Kaydediliyor…',
    'commit_count' => 'Hepsini kaydet (:count işlem) →',
    'commit_empty' => 'Hepsini kaydet (—) →',
    'skip' => 'Şimdilik atla',

    'errors' => [
        'nothing_to_commit' => 'Kaydedilecek bir şey yok.',
        'commit_failed' => 'Hesap ekstrelerini kaydedemedik. Hiçbir şey değişmedi — yeniden dene.',
    ],

    'section' => [
        'from_prefix' => 'KAYNAK: ',
        'from_bank' => 'BANKA EKSTRENDEN',
        'from_ics' => 'ICS KART EKSTRELERİNDEN',
        'from_paypal' => "PAYPAL'DAN",
        'row' => ':count SATIR',
        'badge_ready' => '✓ HAZIR',
        'badge_empty' => 'BOŞ',
        'badge_error' => 'YENİDEN YÜKLENMELİ',
        'error_body' => 'Bu kaynaktaki dosyaların tamamını okuyamadık. Başka bir dosya dene →',
        'partial_body' => 'Bu dosyalardan biri tamamen okunamadı, bu yüzden tamamı dışarıda bırakıldı: :reason',
        'empty_body' => 'Bu hesap ekstresi boş.',
        'col_date' => 'Tarih',
        'col_type' => 'Tür',
        'col_counterparty' => 'Karşı taraf',
        'col_amount' => 'Tutar',
        'load_more' => 'Daha fazla yükle (:remaining kaldı)',
        'rows_shown' => ':count satır gösteriliyor',
    ],
];
