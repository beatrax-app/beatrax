<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Gmail OAuth istemcini kur',
    'microsoft_title' => 'Microsoft 365 OAuth istemcini kur',
    'intro' => 'Beatrax kendi Google Cloud projeni / Azure uygulama kaydını kullanır, böylece kimlik bilgilerin hiçbir zaman ortak bir sunucuya ulaşmaz. Bu, her sağlayıcı için tek seferlik bir kurulumdur.',

    'copied' => 'Kopyalandı',
    'cancel' => 'İptal',
    'save_connect' => 'Kaydet ve bağlan',

    'secret_help' => 'Bunlar, veritabanının dışında kısıtlı izinlere sahip yerel bir yapılandırma dosyasında saklanır ve bu cihazdan asla çıkmaz.',

    'gmail' => [
        'step1_title' => "Google Cloud Console'u aç",
        'step1_body' => "Google Cloud Console'u yeni bir sekmede aç. Taramak istediğin Google hesabıyla giriş yap, ardından yeni bir proje oluştur (veya mevcut bir kişisel projeyi seç).",
        'step1_link' => "Google Cloud Console'u aç",
        'step2_title' => "Gmail API'yi etkinleştir",
        'step2_body' => 'Yeni projede, API Library içinde "Gmail API" araması yapıp Enable düğmesine tıkla. Bu, projeye senin adına Gmail çağrısı yapma yetkisi verir.',
        'step3_title' => 'OAuth izin ekranını yapılandır',
        'step3_body' => 'APIs & Services → OAuth consent screen bölümünü aç. User type olarak "External" seç, uygulama adı olarak "Beatrax" ve destek ile geliştirici iletişimi olarak kendi e-posta adresini gir. https://www.googleapis.com/auth/gmail.readonly kapsamını ekle. Save and continue, ardından Back to Dashboard düğmesine tıkla.',
        'step4_title' => 'İzin ekranını "In production" durumuna geçir',
        'step4_body' => 'OAuth consent screen sayfasında Publish App düğmesine tıklayıp onayla. Bu gereklidir — bu yapılmazsa Beatrax uygulamasının aldığı yenileme belirteçlerinin süresi 7 gün sonra dolar. Tek kullanıcı sen olduğunda yayımlamak için Google incelemesi gerekmez.',
        'step4_checkbox' => 'OAuth izin ekranını In production durumuna yayımladım',
        'step5_title' => 'OAuth Client ID oluştur',
        'step5_body' => 'Credentials → Create Credentials → OAuth Client ID bölümünü aç. Uygulama türü olarak "Web application" seç. Ad olarak "Beatrax" gir. "Authorized redirect URIs" altına aşağıdaki URI adresini birebir yapıştır.',
        'step6_title' => 'Client ID ve client secret değerini yapıştır',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => "Azure Portal'ı aç",
        'step1_body' => 'Microsoft Entra yönetim merkezini yeni bir sekmede aç. Taramak istediğin Microsoft hesabıyla giriş yap.',
        'step1_link' => "Azure Portal'ı aç",
        'step2_title' => 'Yeni bir uygulama kaydet',
        'step2_body' => 'App registrations → New registration bölümünü aç. Adını "Beatrax" koy. "Supported account types" altında "Accounts in any organizational directory and personal Microsoft accounts" seçeneğini seç (bu, kişisel Outlook.com ve iş Microsoft 365 gelen kutularını aynı uygulamayla bağlamanı sağlar).',
        'step3_title' => 'Yönlendirme URI adresini ekle',
        'step3_body' => 'Aynı kayıt formunda "Redirect URI" altında platform olarak "Web" seç ve aşağıdaki URI adresini birebir yapıştır.',
        'step4_title' => 'Mail.Read iznini ver',
        'step4_body' => 'API permissions → Add a permission → Microsoft Graph → Delegated permissions bölümünü aç. Mail.Read ve offline_access seçeneklerini işaretle. Add permissions düğmesine tıkla. Kişisel bir hesap için yönetici onayı vermen gerekmez.',
        'step5_title' => 'Bir client secret oluştur',
        'step5_body' => 'Certificates & secrets → New client secret bölümünü aç. Açıklama olarak "Beatrax" ve 24 aylık bir son kullanma süresi belirle. Secret değerini hemen kopyala — Azure bunu yalnızca bir kez gösterir.',
        'step6_title' => 'Uygulama (client) ID ve secret değerini yapıştır',
        'client_id_label' => 'Uygulama (client) ID',
        'client_secret_label' => 'Client secret değeri',
    ],

    'errors' => [
        'pick_provider' => 'Göndermeden önce bir sağlayıcı seç.',
        'microsoft_client_id' => 'Uygulama (client) ID değerini gir — 12345678-1234-1234-1234-123456789abc gibi bir UUID.',
        'microsoft_secret' => 'Secret oluşturduğunda Azure tarafından gösterilen client secret değerini gir.',
        'google_client_id' => '.apps.googleusercontent.com ile biten bir Google OAuth client ID gir.',
        'google_secret' => 'GOCSPX- ile başlayan bir Google OAuth client secret gir.',
        'google_published' => "OAuth izin ekranını 'In production' durumuna geçirdiğini onayla.",
        'write_failed' => 'OAuth istemcin diske kaydedilemedi — secrets dizininin izinlerini kontrol edip yeniden dene.',
    ],
];
