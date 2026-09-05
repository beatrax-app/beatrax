<?php

declare(strict_types=1);

return [
    'tip' => [
        'about' => ':subject hakkında',
        'close' => 'Kapat',
    ],

    'page_title' => 'Verilerim nerede?',
    'intro' => 'Beatrax her şeyi bu cihazda saklar. Beatrax sunucusu ve bulut hesabı yoktur. Dışarı yalnızca senin bağladığın şeyler çıkar — bir gelen kutusu, Enable Banking üzerinden bir banka, senkronizasyon için eşleştirdiğin cihazlar — ve bir de günlük döviz kuru sorgusu. Her bağlantı bunu, onu açtığın ekranda söyler.',

    'lives_here' => 'Verilerin burada duruyor',
    'copy' => 'Kopyala',
    'copied' => 'Kopyalandı',

    'location' => [
        'database' => 'Veritabanı:',
        'artefacts_imports' => 'İçe aktarılan ekstreler:',
        'artefacts_mail' => 'Taranan posta:',
        'artefacts_drop' => 'İzlenen klasör:',
        'backups' => 'Yedekler:',
        'secrets' => 'Bağlantı kimlik bilgileri:',
        'logs' => 'Günlükler:',
    ],

    'copy_aria' => [
        'database' => 'Veritabanı yolunu panoya kopyala',
        'artefacts_imports' => 'İçe aktarılan ekstrelerin yolunu panoya kopyala',
        'artefacts_mail' => 'Taranan postanın yolunu panoya kopyala',
        'artefacts_drop' => 'İzlenen klasörün yolunu panoya kopyala',
        'backups' => 'Yedeklerin yolunu panoya kopyala',
        'secrets' => 'Bağlantı kimlik bilgilerinin yolunu panoya kopyala',
        'logs' => 'Günlüklerin yolunu panoya kopyala',
    ],

    'artefacts_heading' => 'Kaynak belgelerin yedeğin içinde değil',
    'artefacts_body' => 'Bir yedek yalnızca veritabanını içerir, başka hiçbir şeyi. İçe aktardığın ekstreler, tarayıcının çektiği postalar ve izlenen klasöre bıraktığın fişler oldukları yerde, yukarıda listelenen üç klasörde kalır. Yedeği güvenli bir yere koymak onları kopyalamaz; yani eksiksiz bir arşiv, o klasörleri de yanına almak demektir — ya da aşağıdaki Her şeyi dışa aktar seçeneğini kullanmak, ki onları yedekle birlikte paketler.',

    'export_heading' => 'Her şeyi dışa aktar',
    'export_body' => "Veritabanının şifrelenmiş bir kopyasını ve Beatrax'a verdiğin her kaynak belgeyi taşıyan tek bir arşiv. İstediğin yerde aç; belgelerin içinde hep oldukları gibi, geldikleri klasörlerde duruyor olacak.",
    'export_passphrase_label' => 'Veritabanı için parola',
    'export_confirm_label' => 'Parolayı yinele',
    'export_passphrase_hint' => 'Arşivin içindeki veritabanı bu parolayla şifrelenir ve parolasız açmanın hiçbir yolu yoktur; o yüzden ileride de elinde olacak bir şey seç. Kaynak belgelerin olduğu gibi girer, bu yüzden arşivi güvendiğin bir yerde tut.',
    'export_cta' => 'Her şeyi ZIP olarak dışa aktar',
    'export_working' => 'Arşiv hazırlanıyor…',

    'delete_heading' => 'Verilerini silme',
    'delete_intro' => 'Verilerin bu cihazdaki dosyalardır; dolayısıyla onları silmek o dosyaları silmek demektir. Burada bunu senin yerine yapan bir düğme yok ve bu bilinçli: geçmişini asıl tutan şey dosya sistemi ve birkaç tabloyu boşaltıp dosyaları yerinde bırakan bir denetim, hiç olmamasından daha kötü olurdu.',
    'delete_uninstall' => "Beatrax'ı kaldırmak verilerini silmez. Bu bilinçli bir tercih — yanlışlıkla yapılan bir kaldırma yılların geçmişini yok etmemeli — bu yüzden aşağıdaki her şey, sen kendin silene kadar bu cihazda kalır.",
    'delete_list_intro' => 'Hiçbir iz kalmaması için bunların her birini sil:',
    'delete_journal_note' => 'Veritabanının yanında iki günlük dosyası durur: :wal ve :shm. En son değişikliklerin, veritabanına işlenene kadar bunların içinde yaşar; o yüzden üçünü birlikte sil.',
    'no_telemetry' => 'Devre dışı bırakılacak bir telemetri ve kapatılacak uzak bir hesap yok.',
];
