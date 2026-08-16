<?php

declare(strict_types=1);

return [
    'what_heading' => 'Hangi konularda bildirim alacaksın',

    'reminders' => [
        'label' => 'Ödeme hatırlatmaları',
        'help' => 'Düzenli bir ödemenin vadesi gelmeden önce haber al.',
    ],

    'lead_days' => [
        'label' => 'Bana ___ gün önce hatırlat',
        'help' => 'Hatırlatmanın vade tarihinden kaç gün önce gönderileceği. 1–30 gün.',
    ],

    'budget_nudges' => [
        'label' => 'Bütçe uyarıları',
        'help' => 'Bir kategori bütçesi neredeyse tükendiğinde haber al.',
    ],

    'digest' => [
        'label' => 'Haftalık durumun',
        'help' => 'Bu dönemde durumun nasıl olduğuna dair özeti ne sıklıkta alacağın.',
        'daily' => 'Günlük',
        'weekly' => 'Haftalık',
        'off' => 'Kapalı',
    ],

    'savings' => [
        'label' => 'Tasarruf fırsatı önerileri',
        'help' => 'Beatrax daha ucuz bir plan ya da tasarruf edebileceğin bir yer bulduğunda haber al.',
    ],

    'when_heading' => 'Ne zaman ve nasıl',

    'quiet_hours' => [
        'label' => 'Sessiz saatler',
        'help' => 'Bu aralıkta ses veya bildirim balonu yok — bildirimler yine de gelen kutuna düşer.',
        'from' => 'Başlangıç',
        'to' => 'Bitiş',
    ],

    'hide_details' => [
        'label' => 'Bildirimlerde ayrıntıları gizle',
        'help' => 'Tutarları ve işyeri adlarını bildirim balonunda göster. Ekranını başkaları görebiliyorsa kapat.',
    ],

    'save' => 'Bildirim ayarlarını kaydet',
    'saved' => 'Kaydedildi.',

    'other_devices' => [
        'summary' => 'Diğer cihazlar',
        'empty' => 'Henüz eşleştirilmiş başka cihaz yok.',
        'unnamed' => 'Adsız cihaz',

        'summary_line' => 'hatırlatmalar :reminders · uyarılar :nudges · özet :digest · tasarruf :savings',
        'on' => 'açık',
        'off' => 'kapalı',
    ],

    'errors' => [
        'save_failed' => 'Bildirim ayarların kaydedilemedi. Yeniden dene.',
    ],
];
