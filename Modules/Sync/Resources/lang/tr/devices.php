<?php

declare(strict_types=1);

return [
    'heading' => 'Cihazlar ve senkronizasyon',

    'enable_sync' => 'Senkronizasyonu etkinleştir',
    'enable_sync_help' => 'Verilerini güvendiğin cihazlar arasında güvenle paylaş. Uygulama kilidi gerekir.',

    'app_lock_notice' => 'Senkronizasyonu etkinleştirmek için önce bir uygulama kilidi belirle.',
    'go_to_app_lock' => 'Uygulama kilidine git',

    'encrypted_at_rest' => 'Veriler durağan halde şifreli',
    'encrypted_at_rest_help' => 'Verilerin uygulama kilidi parolanla korunuyor.',
    'on' => 'Açık',
    'securing' => 'Verilerin güvenceye alınıyor…',
    'do_not_close' => 'Bu pencereyi kapatma.',
    'encryption_progress_aria' => 'Şifreleme ilerlemesi',
    'not_encrypted_offer' => 'Verilerin durağan halde şifreli değil. Bu cihaz kaybolur ya da çalınırsa verilerini korumak için şifrelemeyi kur.',
    'enable_encryption' => 'Şifrelemeyi etkinleştir',

    'your_devices' => 'Cihazların',

    'moved_help' => 'Eşleştirme, cihaz adları ve şifreleme artık senkronizasyon durumunun yanında yer alıyor.',
    'moved_cta' => 'Senkronizasyon ve cihazı aç',
    'device_name' => 'Cihaz adı',
    'save' => 'Kaydet',
    'peer_default_name' => 'Eşleştirilmiş cihaz',
    'rename_device' => 'Cihazı yeniden adlandır',
    'this_device' => 'Bu cihaz',
    'removed' => 'Kaldırıldı',
    'confirmed' => 'Onaylandı',
    'awaiting_confirmation' => 'Onay bekleniyor',
    'safety_number_words' => 'Güvenlik numarası kelimeleri:',
    'paired' => 'Eşleştirildi',
    'remove_aria' => ':name cihazını kaldır',
    'remove' => 'Kaldır',
    'pair_new_device' => 'Yeni bir cihaz eşleştir',

    'relay_endpoint' => 'Relay uç noktası',
    'relay_endpoint_help' => 'İsteğe bağlı. Ayarlandığında çevrimdışı cihazlar bu relay üzerinden senkronize olur. Yalnızca LAN&#8209;doğrudan bağlantı için boş bırak.',
    'relay_endpoint_aria' => 'Relay uç noktası URL adresi',
    'relay_insecure_warning' => 'Bu relay uç noktası düz HTTP kullanıyor. Relay verilerinin şifresini hiçbir zaman çözmese de güvenli olmayan bir bağlantı, şifreli veri boyutlarını ve zamanlamayı ağı izleyenlere açık eder. En iyi gizlilik için <strong>https://</strong> uç noktası kullan.',

    'enable_at_rest' => 'Durağan hal şifrelemesini etkinleştir',
    'enable_at_rest_body' => 'Verilerin uygulama kilidi parolan kullanılarak şifrelenecek. Geçiş öncesinde otomatik olarak bir yedek oluşturulacak.',
    'no_recovery_warning' => 'Uygulama kilidi parolanı kaybedersen ve yedeğin ya da güvendiğin başka bir cihazın yoksa verilerin kurtarılamaz.',
    'recover_help' => 'Erişimi geri kazanmak için bu cihazı güvendiğin başka bir cihazdan yeniden eşleştir veya bağımsız şifreli yedeğini kullan.',
    'amounts_plaintext' => 'Tutarlar durağan halde şifrelenmez — aylık toplamların doğru çıkmaya devam etsin diye bakiyeler ve toplamlar okunabilir kalır.',
    'search_plaintext' => 'Tam metin araması çalışmaya devam etsin diye arama dizini, işyeri ve açıklama metninin düz bir kopyasını saklar.',
    'keep_unencrypted' => 'Verileri şifresiz bırak',
    'encryption_enabled' => 'Şifreleme etkinleştirildi',
    'encryption_enabled_body' => 'Verilerin artık durağan halde şifreli.',
    'done_encryption_enabled' => 'Bitti — şifreleme etkinleştirildi',
    'encryption_failed' => 'Şifreleme kurulumu başarısız oldu',
    'encryption_failed_body' => 'Verilerin değiştirilmedi. Yedeğin korundu.',
    'close_no_changes' => 'Kapat — değişiklik yapılmadı',

    'remove_this_device' => 'Bu cihazı kaldır',
    'removing' => 'Kaldırılıyor:',
    'remove_rotates_key' => 'Bu cihazı kaldırmak şifreleme anahtarını değiştirir, böylece cihaz sonraki güncellemeleri almaz.',
    'remove_cannot_erase' => 'Bu işlem, o cihazda hâlihazırda bulunan verileri silemez. Cihaz kaybolduysa ya da çalındıysa içindeki tüm verileri açığa çıkmış say.',
    'remove_device' => 'Cihazı kaldır',
    'keep_device' => 'Cihazı tut',
    'rotating_key' => 'Şifreleme anahtarı değiştiriliyor…',

    'flash' => [
        'app_lock_first' => 'Senkronizasyonu etkinleştirmek için önce bir uygulama kilidi belirle.',
        'enable_failed' => 'Senkronizasyon etkinleştirilemedi. Uygulama kilidinin etkin olduğundan emin olup yeniden dene.',
        'cannot_remove_self' => 'Bu cihazı kaldıramazsın — şu anda kullandığın cihaz.',
        'remove_failed' => 'Cihaz kaldırılamadı. Lütfen yeniden dene.',
        'app_lock_first_settings' => 'Senkronizasyon ayarlarını değiştirmek için önce bir uygulama kilidi belirle.',
        'relay_cleared' => 'Relay uç noktası temizlendi.',
        'relay_saved' => 'Relay uç noktası kaydedildi.',
        'relay_save_failed' => 'Relay uç noktası kaydedilemedi: :message',
    ],
];
