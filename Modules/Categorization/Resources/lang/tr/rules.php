<?php

declare(strict_types=1);

return [
    'page_title' => 'Kurallar',
    'heading' => 'Kurallar',
    'intro' => 'İşlemleri içe aktarırken önceden kategorilendir. Kurallar her kaynağa uygulanır — banka, kart, PayPal ve e-posta fişleri.',

    'reapply' => 'Kuralları geçmişe yeniden uygula',
    'reapplying' => 'Yeniden uygulanıyor…',
    'new_rule' => 'Yeni kural',

    'reapply_progress_lead' => 'Kurallar yeniden uygulanıyor…',
    'reapply_progress_of' => '/',
    'reapply_progress_trail' => 'işlem kontrol edildi',

    'empty_heading' => 'Henüz kural yok',
    'empty_body' => 'Kurallar işlemleri birden fazla koşula göre eşleştirir ve kategori, karşı taraf, not ve vergi etiketi değişikliklerini otomatik olarak uygular — içe aktarma sırasında ve mevcut geçmişine her yeniden uyguladığında.',
    'empty_cta' => 'İlk kuralını oluştur',

    'col_priority' => 'Öncelik',
    'col_conditions' => 'Koşullar',
    'col_actions' => 'Eylemler',
    'col_hits' => 'Eşleşme',
    'col_created' => 'Oluşturuldu',
    'col_row_actions' => 'Eylemler',

    'more_conditions' => '+:count daha',

    'delete_confirm' => 'Silinsin mi?',
    'delete_yes' => 'Evet, sil',
    'cancel' => 'İptal',
    'edit' => 'Düzenle',
    'delete' => 'Sil',
    'edit_aria' => 'Kuralı düzenle (öncelik :priority)',
    'delete_aria' => 'Kuralı sil (öncelik :priority)',

    'footer_note' => "Kurallar ve işyeri geçmişi birlikte çalışır. Bir kuralı silmek, Beatrax'ın geçmiş kategorilendirmelerden öğrendiklerini temizlemez — sonraki içe aktarma yine de geçmişten aynı kategoriyi önerebilir.",

    'chip_category' => 'Kategori: :path',
    'chip_counterparty' => 'Karşı taraf: :path',
    'chip_note' => 'Not',
    'chip_tax_tag' => 'Vergi etiketi',

    'flash_deleted' => 'Kural silindi.',
    'flash_not_found' => 'Kural bulunamadı (başka bir sekmede silinmiş olabilir).',
    'flash_saved' => 'Kural kaydedildi.',
    'flash_reapplying' => 'Kurallar geçmişine yeniden uygulanıyor…',
    'summary_no_changes' => 'Değişiklik yok — geçmişin kurallarınla zaten uyumlu.',
    'summary_updated' => ':transactions işlemde :fields alan güncellendi.',
    'summary_reconciled_skipped' => 'Mutabakatlı :count işlem atlandı.',
];
