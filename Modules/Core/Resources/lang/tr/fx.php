<?php

declare(strict_types=1);

return [
    'rate_details' => 'Kur ayrıntıları',
    'rate_details_for' => 'Kur ayrıntıları: :name',

    'converted_to' => 'Şu para birimine dönüştürüldü: :currency',
    'as_of' => ':date tarihli',
    'as_of_age' => ':date (:ago)',
    'rate_line' => '1 :from = :rate :to',
    'global_rates' => 'kurlar :date tarihli, kaynak :source',

    'stale_bundled' => 'Uygulamayla birlikte gelen, :count günden eski anlık kur kullanılıyor. Güncel kurlar için Ayarlar bölümünden çevrimiçi yenilemeyi aç.',
    'stale_old' => 'Bu kur :count günden eski. Bir sonraki çevrimiçi yenileme onu güncelleyecek.',
    'stale_offline' => 'Bu kur :count günden eski ve çevrimiçi yenileme kapalı. Güncellenmesi için Ayarlar bölümünden aç.',

    'source_ecb' => 'ECB',
    'source_bundled' => 'Yerleşik anlık görüntü',
    'source_transaction' => 'Kaydedilen kur',
    'source_fallback' => 'kurlar',
];
