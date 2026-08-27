<?php

declare(strict_types=1);

return [
    'page_title' => 'İşlemler',
    'heading' => 'İşlemler',

    'subtitle_searching' => 'Tüm geçmişte aranıyor',
    'subtitle_full' => 'Tüm geçmiş.',
    'subtitle_recent' => 'Son işlemler (son 90 gün).',

    'currency_aria' => 'Para birimi görünümü',
    'currency_eur' => 'Yalnızca :code',
    'currency_original' => 'Orijinal para birimi',

    'show_recent' => 'Yalnızca son işlemleri göster',
    'show_full' => 'Tüm geçmişi göster',

    'empty_period' => 'Bu dönem için burada bir şey yok.',


    'empty_recent_has_older' => 'Son 90 günde bir şey yok. Daha eski işlemleriniz hâlâ burada.',

    'empty_history' => 'Henüz işlem yok.',
    'loading_more' => 'Daha fazla işlem yükleniyor',
    'load_more' => 'Daha fazla yükle',

    'split_badge' => 'Bölüştürme · :count',
    'split_expand_aria' => ':count kategoriye bölüştürüldü — görmek için genişlet',

    'chain_badge' => 'zincir',
    'chain_title' => 'Bir zincirin parçası — görmek için bu satırı aç',

    'table' => [
        'date' => 'Tarih',
        'counterparty' => 'Karşı taraf',
        'category' => 'Kategori',
        'tax' => 'Vergi',
        'status' => 'Durum',
        'amount' => 'Tutar',
    ],

    'search' => [
        'placeholder' => 'İşyeri, açıklama, not ara…',
        'placeholder_short' => 'İşlem ara…',
        'aria' => 'İşlemlerde ara',
        'clear_all' => 'Tümünü temizle',
        'filters' => 'Filtreler',
        'open_filters_aria' => 'Filtreleri aç',
        'apply' => 'Uygula',
        'clear' => 'Temizle',

        'count' => ':count işlem',
        'matching_suffix' => 'filtrelerle eşleşiyor',
        'flow' => ':out çıkan / :in giren',
    ],

    'no_results' => [
        'heading' => 'Eşleşen yok',
        'remove_prompt' => 'Sonuçları daraltıyor olabilecek bir filtreyi kaldırmayı dene:',
        'no_match_query' => 'Tüm geçmişte “:query” ile eşleşen işlem yok.',
        'no_match_filters' => 'Uygulanan filtrelerle eşleşen işlem yok.',
        'did_you_mean' => 'Şunu mu demek istedin:',
        'account_fallback' => 'Hesap :id',
        'category_fallback' => 'Kategori :id',
    ],

    'filter' => [
        'date' => 'Tarih',
        'account' => 'Hesap',
        'amount' => 'Tutar',
        'category' => 'Kategori',
        'date_range' => 'Tarih aralığı',
        'from' => 'Başlangıç',
        'to' => 'Bitiş',
        'custom_range' => 'Özel aralık ×',
        'after' => ':date tarihinden sonra ×',
        'before' => ':date tarihinden önce ×',
        'dir_both' => 'İkisi de',
        'dir_in' => 'Giren',
        'dir_out' => 'Çıkan',
        'min' => 'Min',
        'max' => 'Maks',
        'min_aria' => 'En düşük tutar',
        'max_aria' => 'En yüksek tutar',
        'after_aria' => 'Şu tarihten sonra',
        'before_aria' => 'Şu tarihten önce',
        'acct' => ':count hesap',
        'cat' => ':count kategori',
        'date_dialog' => 'Tarih filtresi',
        'account_dialog' => 'Hesap filtresi',
        'amount_dialog' => 'Tutar filtresi',
        'category_dialog' => 'Kategori filtresi',
        'remove_date_aria' => 'Tarih filtresini kaldır',
        'remove_account_aria' => 'Hesap filtresini kaldır',
        'remove_amount_aria' => 'Tutar filtresini kaldır',
        'remove_category_aria' => 'Kategori filtresini kaldır',

        'remove_named_aria' => ':name filtresini kaldır',
    ],

    'date_preset' => [
        'this_month' => 'Bu ay',
        'last_month' => 'Geçen ay',
        'this_year' => 'Bu yıl',
        'last_year' => 'Geçen yıl',
    ],
];
