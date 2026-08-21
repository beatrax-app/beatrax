<?php

declare(strict_types=1);

return [
    'page_title' => 'İçe aktarma tamamlandı',
    'heading' => 'İçe aktarma tamamlandı',

    'summary' => ':count işlem içe aktarıldı',
    'summary_duplicates' => ' · :count yinelenen atlandı',
    'summary_enriched' => ' · :count zenginleştirildi',
    'summary_errors' => ' · :count hata',

    'show_duplicates' => 'Atlanan yinelenenleri göster (:count)',
    'duplicates_help' => 'Yinelenenler, defterinde zaten bulunan satırlardır — yeniden içe aktarmada sessizce atlanır.',
    'show_errors' => 'Hataları göster (:count)',
    'errors_help' => 'Hatalar ayrıştırılamayan satırlardır; defterine eklenmediler.',

    'upload_another' => 'Başka bir hesap ekstresi yükle',

    'issues' => [
        'row' => 'Satır :row: :reason',
        'file_stopped' => 'Dosya :row. satırdan ötesi okunamadı. O satırdan sonrası içe aktarılmadı.',
        'file_none' => 'Dosya hiç okunamadı.',
        'detail' => 'Okuyucunun bildirdiği: :reason',
        'duplicate' => 'Satır :row zaten defterindeydi.',
        'more' => '+ :count listelenmedi',
        'unknown_reason' => 'Herhangi bir neden kaydedilmedi.',
    ],
];
