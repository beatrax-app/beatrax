<?php

declare(strict_types=1);

return [
    'uncategorized' => 'Kategorisiz',
    'title' => 'Raporlar',
    'page_title' => 'Raporlar · Beatrax',
    'subtitle' => 'Defterinden bir rapor oluştur.',
    'controls_aria' => 'Rapor denetimleri',
    'result_aria' => 'Rapor sonucu',
    'dismiss' => 'Kapat',

    'metric' => [
        'heading' => 'Metrik',
        'spend' => 'Harcama',
        'income' => 'Gelir',
        'net' => 'Net',
        'net_worth' => 'Net varlık',
        'fallback' => 'Tutar',
    ],

    'group_by' => 'Şuna göre grupla',

    'dimension' => [
        'category' => 'Kategori',
        'time_bucket' => 'Zaman aralığı',
        'counterparty' => 'Karşı taraf',
        'account' => 'Hesap',
    ],

    'period' => [
        'heading' => 'Dönem',
        'this_month' => 'Bu ay',
        'last_3_months' => 'Son 3 ay',
        'last_6_months' => 'Son 6 ay',
        'last_12_months' => 'Son 12 ay',
        'ytd' => 'Yıl başından bugüne',
        'this_year' => 'Bu yıl',
        'custom' => 'Özel aralık',
        'from' => 'Başlangıç',
        'to' => 'Bitiş',
    ],

    'currency' => [
        'heading' => 'Para birimi',
        'aria' => 'Para birimi modu',
        'base' => 'Temel',
        'original' => 'Orijinal',
    ],

    'granularity' => [
        'heading' => 'Ayrıntı düzeyi',
        'aria' => 'Zaman ayrıntı düzeyi',
        'monthly' => 'Aylık',
        'weekly' => 'Haftalık',
    ],

    'filters' => [
        'heading' => 'Filtreler',
    ],

    'compare' => 'Önceki dönemle karşılaştır',

    'viz' => [
        'heading' => 'Görselleştirme',
        'table' => 'Tablo',
        'bar' => 'Sütun',
        'line' => 'Çizgi',
        'donut' => 'Halka',
    ],

    'actions' => [
        'update_report' => 'Raporu güncelle',
        'save_report' => 'Raporu kaydet',
        'report_name' => 'Rapor adı',
        'update' => 'Güncelle',
        'save' => 'Kaydet',
        'cancel' => 'İptal',
        'export_csv' => "CSV'ye aktar",
    ],

    'updating' => '… Güncelleniyor',

    'empty' => [
        'heading' => 'Bu seçim için gösterilecek bir şey yok',
        'body' => 'Tarih aralığını genişletmeyi ya da bir filtreyi kaldırmayı dene.',
    ],

    'total_prefix' => 'Toplam',
    'total' => 'Toplam',
    'vs_previous' => 'önceki döneme göre',
    'view_transactions' => 'İşlemleri görüntüle',

    'fx_excluded' => ':count hesap dönüştürülmedi — kur bulunamadı',

    'group_header' => [
        'category' => 'Kategori',
        'counterparty' => 'Karşı taraf',
        'account' => 'Hesap',
        'month' => 'Ay',
        'default' => 'Grup',
    ],

    'chart' => [
        'bar_title' => 'İşlemlerini görmek için bir sütuna tıkla',
        'line_title' => 'İşlemlerini görmek için bir noktaya tıkla',
        'donut_title' => 'İşlemlerini görmek için bir dilime tıkla',
    ],

    'flash' => [
        'saved' => 'Rapor kaydedildi.',
        'updated' => 'Rapor güncellendi.',
    ],

    'filter' => [
        'account' => 'Hesap',
        'account_count' => ':count hesap',
        'remove_account' => 'Hesap filtresini kaldır',
        'account_dialog' => 'Hesap filtresi',

        'category' => 'Kategori',
        'category_count' => ':count kategori',
        'remove_category' => 'Kategori filtresini kaldır',
        'category_dialog' => 'Kategori filtresi',

        'counterparty' => 'Karşı taraf',
        'counterparty_count' => ':count karşı taraf',
        'remove_counterparty' => 'Karşı taraf filtresini kaldır',
        'counterparty_dialog' => 'Karşı taraf filtresi',

        'amount' => 'Tutar',
        'remove_amount' => 'Tutar filtresini kaldır',
        'amount_dialog' => 'Tutar filtresi',
        'dir_both' => 'İkisi de',
        'dir_in' => 'Giren',
        'dir_out' => 'Çıkan',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'En düşük tutar',
        'max_aria' => 'En yüksek tutar',
    ],

    'other_movement' => 'Ücretler ve düzeltmeler (yukarıda sayılmadı)',
];
