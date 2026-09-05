<?php

declare(strict_types=1);

return [
    'page_title' => 'Bu cihaz senkronize edildi',
    'heading' => 'Bu cihaz senkronize edildi',
    'records' => ':peer cihazından :count kayıt kopyalandı.',
    'records_none' => ':peer ile güncelsin. Kopyalanacak yeni bir şey yoktu.',
    'withheld' => ':count değişiklik henüz ulaşmadı.',
    'withheld_action' => 'Bu cihazın doğrulayamadığı bir cihaz tarafından imzalandılar. Hiçbir şey kaybolmuyor — hepsi :peer cihazında kalır ve cihazlarından biri o kimliği ilettiğinde ve sen onu :section bölümünde onayladığında ulaşırlar.',
    'how_it_works' => 'Bundan sonrası',
    'automatic_title' => 'Ne zaman senkronize olacağına sen karar verirsin',
    'automatic_body' => 'İki cihazdan birinde yaptığın her değişiklik, :action düğmesine bir sonraki dokunuşunda diğerinde de görünür. Arka planda çalışamaz — uygulama kilidi tek anahtarı tutuyor.',
    'lan_title' => 'Aynı ağdayken',
    'lan_body' => 'Her iki cihaz da ev ağındayken aralarında hiçbir aracı olmadan doğrudan iletişim kurar.',
    'relay_title' => 'Dışarıdayken',
    'relay_body' => 'Değişiklikler, diğer cihaz yeniden çevrimiçi olana kadar relay sunucunda şifreli olarak bekler. Bu cihaz, :action düğmesine bir sonraki dokunuşunda onları alır.',
    'no_relay_title' => 'Dışarıdayken',
    'no_relay_body' => 'Değişiklikler bu cihazda bekler; her iki cihaz ev ağında bir araya gelip burada :action düğmesine dokunduğunda senkronize olur.',
    'encrypted_title' => 'Yalnızca cihazların okuyabilir',
    'encrypted_body' => 'Her şey bir cihazdan ayrılmadan önce şifrelenir ve anahtarlar yalnızca eşleştirdiğin cihazlarda bulunur.',
    'continue' => "Beatrax'ı kullanmaya başla",
    'peer_fallback' => 'diğer cihazın',
];
