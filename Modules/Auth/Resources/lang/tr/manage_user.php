<?php

declare(strict_types=1);

return [
    'page_title' => 'Yönet: :name · Beatrax',
    'heading' => 'Yönet: :name',
    'subtitle' => 'Bu kullanıcının kodlarını görüntüle, sıfırla veya yeniden oluştur.',

    'set_password' => [
        'heading' => 'Bu kullanıcı için yeni parola belirle',
        'description' => 'Sonraki girişinde bir parola seçmesi istenecek.',
        'open' => 'Bu kullanıcı için yeni parola belirle',
        'body' => ':name için yeni bir parola belirle. Sonraki girişinde bir parola seçmesi istenecek.',
        'label' => 'Yeni parola',
        'submit' => 'Parolayı belirle',
        'cancel' => 'İptal',
    ],

    'regenerate' => [
        'heading' => 'Bu kullanıcı için kurtarma kodlarını yeniden oluştur',
        'description' => 'Eski kodlar geçersiz olacak.',
        'open' => 'Bu kullanıcı için kurtarma kodlarını yeniden oluştur',
        'body' => 'Kullanılmamış mevcut kodları çalışmayı bırakacak. 10 yeni kodu bir kez görecek ve teslim edebileceksin.',
        'confirm_label' => 'Devam etmek için kullanıcı adını yaz',
        'submit' => 'Kodları yeniden oluştur',
        'keep' => 'Mevcut kodları koru',
        'download' => '.txt olarak indir',
    ],

    'error_min_length' => 'En az 12 karakter kullan.',
    'password_set' => ':name için parola belirlendi. Sonraki girişinde bir parola seçmesi istenecek.',
    'codes_regenerated' => ':name için on yeni kurtarma kodu oluşturuldu.',
];
