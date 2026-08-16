<?php

declare(strict_types=1);

return [
    'title' => 'Düzenli işlemleri gözden geçir',
    'subtitle' => 'Algılanan düzenli işlem önerilerini onayla, ertele veya reddet.',

    'tabs' => [
        'pending' => 'Beklemede',
        'rejected' => 'Reddedilen',
        'cadence_changed' => 'Sıklık değişti',
    ],

    'bulk' => [
        'aria' => 'Toplu eylemler',
        'selected' => ':count seçildi',
        'approve' => ':count tanesini onayla',
        'reject' => ':count tanesini reddet',
    ],

    'empty' => [
        'heading' => 'Gözden geçirilecek bir şey yok',
        'pending' => 'Algılayıcı istikrarlı aylık kümeler bulduğunda düzenli işlem önerileri burada görünür.',
        'rejected' => 'Reddedilen öneriler burada görünür; fikrin değişirse geri getirebilirsin.',
        'cadence_changed' => 'Sıklığı değişen onaylı seriler yeniden gözden geçirilmek üzere burada görünür.',
    ],

    'next' => 'Sonraki',
    'cadence_changed_note' => 'sıklık değişti',

    'select_aria' => ':id numaralı düzenli seriyi seç',
    'un_reject' => 'Reddi geri al',
    'approve' => 'Onayla',
    'approve_aria' => ':id numaralı düzenli seriyi onayla',
    'reject' => 'Reddet',
    'reject_aria' => ':id numaralı düzenli seriyi reddet',
    'snooze' => 'Ertele',
    'snooze_1w' => '1 hafta',
    'snooze_1m' => '1 ay',
    'snooze_3m' => '3 ay',
    'edit_name' => 'Adı düzenle',
    'new_name_label' => 'Bu seri için yeni ad',
    'save' => 'Kaydet',

    'toast' => [
        'approved' => 'Onaylandı',
        'rejected' => 'Reddedildi',
        'snoozed' => 'Ertelendi',
        'renamed' => 'Yeniden adlandırıldı',
        'un_rejected' => 'Reddi geri alındı',
        'bulk_approved' => ':count tanesi onaylandı',
        'bulk_rejected' => ':count tanesi reddedildi',
    ],
];
