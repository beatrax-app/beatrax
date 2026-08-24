<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Beatrax\'ın bu sürümünde kilit açma anahtarını saklayacak bir yer yok, bu yüzden biyometrik kilit açma sunulmuyor. Sınırlama cihazın değil.',
    'error_enroll_unprotected' => 'Biyometrik kilit açma, işletim sisteminin anahtar deposuna ihtiyaç duyar ve bu kurulumda böyle bir depo yok. Kaydolmak, kilit açma anahtarını verilerinin yanında okunabilir bırakırdı; bu yüzden burada sunulmuyor.',
    'error_enroll_locked' => 'Kaydetmeden önce uygulamanın kilidini aç.',
    'error_enroll_failed' => 'Cihazın anahtarı saklamayı reddetti. Biyometrik kilit açma kullanılamıyor.',
    'heading' => 'Uygulama kilidi',

    'moved_help' => 'PIN kodun, otomatik kilitleme süren ve biyometrik kilit açma, bu cihazın senkronizasyon ayarlarında yer alır.',
    'moved_cta' => 'Senkronizasyon ve cihazı aç',

    'toggle_label' => 'Uygulamayı PIN ile kilitle',
    'toggle_description' => 'Günlük girişin yerini PIN alır. Oturumlar 30 gün açık kalır.',

    'setup_heading' => 'Kilidi etkinleştirmek için bir PIN belirle',
    'new_pin_label' => 'Yeni PIN (6–10 hane)',
    'confirm_pin_label' => 'PIN doğrulama',
    'account_password_label' => 'Hesap parolası',
    'account_password_note' => '(kurtarma anahtarı oluşturmak için gerekli)',
    'account_password_placeholder' => 'Hesap parolan',
    'set_pin' => 'PIN belirle',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Mevcut PIN kodunu değiştir.',
    'change_pin' => 'PIN değiştir',
    'forgot_pin_link' => 'PIN kodunu mu unuttun? Hesap parolanla sıfırla.',

    'biometric_enrolled_description' => 'Bu cihaz biyometrik kilit açma için kayıtlı.',
    'biometric_enroll_description' => 'Biyometrik olarak açabilmek için bu cihazı kaydet.',
    'remove' => 'Kaldır',
    'enroll' => 'Kaydet',
    'biometric_unavailable' => 'Beatrax\'ın bu sürümü biyometrik kilit açma sunamıyor. Burada tek kilit açma yolun PIN kodun.',

    'deenroll_modal_heading' => 'Biyometrik kilit açmayı kaldır — PIN ile onayla',
    'current_pin_label' => 'Mevcut PIN',
    'remove_biometric' => 'Biyometriyi kaldır',
    'keep_biometric' => 'Biyometriyi koru',

    'auto_lock' => 'Şu süreden sonra otomatik kilitle',
    'idle_1' => '1 dakika',
    'idle_5' => '5 dakika',
    'idle_15' => '15 dakika',
    'idle_30' => '30 dakika',

    'disable_modal_heading' => 'Uygulama kilidini kapat — PIN ile onayla',
    'disable_lock' => 'Kilidi kapat',
    'keep_lock' => 'Uygulama kilidini koru',

    'forgot_modal_heading' => 'PIN sıfırla — hesap parolasıyla onayla',
    'forgot_modal_body' => 'Hesap parolan kilit anahtarını kurtarır, bu yüzden PIN sıfırlamak asla veri kaybettirmez.',
    'confirm_new_pin_label' => 'Yeni PIN doğrulama',
    'reset_pin' => 'PIN sıfırla',
    'cancel' => 'İptal',

    'change_modal_heading' => 'PIN değiştir — mevcut PIN ile onayla',
    'keep_pin' => 'PIN kodunu koru',

    'error_pin_too_short' => 'PIN en az 6 haneli olmalı.',
    'error_pin_digits' => 'PIN 6 ila 10 haneli olmalı — yalnızca rakam.',
    'error_pin_mismatch' => 'PIN kodları eşleşmiyor. Yeniden dene.',
    'error_pin_required' => 'PIN kodunu gir.',
    'error_pin_incorrect' => 'Hatalı PIN.',
    'error_account_password_required' => 'Hesap parolanı gir.',
    'error_account_password' => 'Hatalı hesap parolası.',
    'change_pin_success' => 'Şifreleme anahtarın yeni PIN kodunla yeniden güvenceye alındı.',
    'error_forgot_failed' => 'PIN sıfırlama başarısız — kurtarma anahtarı kullanılamıyor.',
    'error_enable_first' => 'Biyometri kaydetmeden önce PIN kilidini etkinleştir.',
    'error_disable_blocked_by_encryption' => 'Notların ve karşı taraf bilgilerin bu uygulama kilidinin tuttuğu anahtarla şifreli, bu yüzden kilidi kapatmak onları okunamaz hâle getirir. Kilit açık kalıyor — bunun yerine PIN kodunu değiştir.',
    'error_key_material_lost' => 'Bu cihaz artık şifreli verilerini açan anahtarı tutmuyor, bu yüzden yeni bir PIN onları yeniden okunur hâle getirmez. Geri almak için bu cihazı anahtarı hâlâ olan bir cihazla eşleştir.',
    'error_recovery_wrap_stale' => 'Hesap parolan artık bu uygulama kilidini açmıyor — kilit kurulduktan sonra değiştirildi. PIN kodun hâlâ çalışıyor, ama unutursan arkasında hiçbir şey kalmıyor. Hesap parolanı şimdi yeniden bağla.',
    'relink_recovery' => 'Hesap parolasını yeniden bağla',
    'relink_modal_heading' => 'Hesap parolasını yeniden bağla — PIN ile onayla',
    'relink_recovery_success' => 'Hesap parolan bu uygulama kilidini yeniden kurtarabilir.',
];
