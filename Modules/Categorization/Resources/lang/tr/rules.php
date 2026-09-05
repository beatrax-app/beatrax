<?php

declare(strict_types=1);

return [
    'page_title' => 'Kurallar',
    'heading' => 'Kurallar',
    'intro' => 'İşlemleri içe aktarırken önceden kategorilendir. Kurallar her kaynağa uygulanır — banka, kart, PayPal ve e-posta fişleri.',
    'device_local_note' => 'Kurallar bu cihazda kalır. Diğer cihazlarınızla paylaşılmaz.',

    'reapply' => 'Kuralları geçmişe yeniden uygula',
    'reapply_confirm' => 'Tüm kurallar geçmişinin tamamına yeniden uygulansın mı? Bir kuralın koyduğu her kategori, karşı taraf, not ve vergi etiketi yeniden yazılır. Elle ayarladıkların olduğu gibi kalır, mutabakatlı bir ekstredeki ve böldüğün bir işlemdeki her şey de öyle. Eski değerleri hiçbir şey geri getirmez.',
    'reapplying' => 'Yeniden uygulanıyor…',
    'new_rule' => 'Yeni kural',

    'reapply_progress' => 'Kurallar yeniden uygulanıyor… :checked / :count işlem kontrol edildi',

    'empty_heading' => 'Henüz kural yok',
    'empty_body' => 'Kurallar işlemleri birden fazla koşula göre eşleştirir ve kategori, karşı taraf, not ve vergi etiketi değişikliklerini otomatik olarak uygular — içe aktarma sırasında ve mevcut geçmişine her yeniden uyguladığında.',
    'empty_cta' => 'İlk kuralını oluştur',

    'col_priority' => 'Öncelik',
    'col_conditions' => 'Koşullar',
    'col_actions' => 'Eylemler',
    'col_hits' => 'Eşleşme',
    'col_created' => 'Oluşturuldu',
    'col_row_actions' => 'Eylemler',
    'inactive_badge' => 'Kapalı',
    'combinator_all' => 'TÜMÜ',
    'combinator_any' => 'HERHANGİ',
    'inactive_title' => 'Bu kural çalışmıyor. İşaret ettiği kategori veya karşı taraf silindiğinde kural kapanır.',

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
    'summary_updated' => ':transactions, :fields güncellendi.',
    'summary_fields' => ':count alan',
    'summary_transactions' => ':count işlemde',
    'summary_reconciled_skipped' => 'Mutabakatlı :count işlem atlandı.',
];
