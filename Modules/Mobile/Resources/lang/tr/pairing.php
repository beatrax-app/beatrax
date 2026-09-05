<?php

declare(strict_types=1);

return [
    'peer_default_name' => 'Eşleştirilmiş cihaz',
    'page_title' => 'Cihaz eşleştir',

    'scan_heading' => 'Bu cihazı eşleştir',
    'scan_subtitle' => 'Kamerayı diğer cihazda görünen koda doğrult.',
    'camera_permission_pending' => 'Kamera erişimi kapalı. Cihaz ayarlarından Beatrax için izin verip yeniden dene.',
    'open_camera' => 'Kamerayı aç',
    'opening_camera' => 'Kamera erişimi bekleniyor…',
    'close_camera' => 'Kamerayı kapat',
    'viewfinder_aria' => 'Kamera vizörü — diğer cihazındaki koda doğrult',
    'viewfinder_idle' => 'Kamera kapalı. Diğer cihazında görünen kodu taramak için kamerayı aç.',
    'scan_prompt' => 'Diğer cihazındaki kodu tara',
    'enter_code_instead' => 'Bunun yerine kodu gir',

    'enter_heading' => 'Kodu gir',
    'camera_off' => 'Kamera erişimi kapalı. Bunun yerine diğer cihazdaki kodu gir.',
    'camera_off_no_search' => 'Kamera erişimi kapalı ve diğer cihazı ağda aramak iPhone’da henüz çalışmıyor — yazılan kodun onu bulacak bir yolu kalmıyor. Cihaz ayarlarından Beatrax için kamera erişimini yeniden aç ve diğer cihazdaki kodu tara.',
    'no_search' => 'Diğer cihazı ağda aramak iPhone’da henüz çalışmıyor, bu yüzden yazılan bir kodun bulacağı bir şey yok. Bunun yerine kodu kamerayla tara — kameranın ağda arama yapması gerekmez.',
    'word_code_aria' => 'Diğer cihazdaki kelime kodunu gir',
    'submit_code' => 'Kodu gönder',
    'cancel' => 'İptal',
    'skip_import' => 'İçe aktarmadan devam et',

    'confirm_heading' => 'Bu kelimeleri diğer cihazla karşılaştır',
    'safety_words_aria' => 'Güvenlik numarası kelimeleri: :words',
    'confirm_body' => 'Her iki cihaz da tam olarak aynı kelimeleri göstermelidir. Farklıysa İptal düğmesine dokun — araya girme (man-in-the-middle) saldırısı sürüyor olabilir.',
    'awaiting_peer' => 'Diğer cihazın onayı bekleniyor...',
    'confirm_match' => 'Onayla — eşleşiyorlar',

    'success_heading' => 'Cihaz eşleştirildi',
    'success_body' => 'Bu cihaz artık güvenilir. Bağlandığında verilerin senkronize olacak.',
    'encryption_incomplete' => 'Cihaz eşleştirildi, ancak üzerinde saklanan verilerin şifrelenmesi tamamlanmadı. Veriler henüz şifreli olarak saklanmıyor.',
    'done' => 'Bitti',

    'errors' => [
        'relay_unreachable' => 'Diğer cihaza ulaşılamıyor. Her ikisinin de aynı ağda olduğundan ve masaüstünde senkronizasyonun açık olduğundan emin ol.',
        'no_road_home' => 'Bu cihaz ağda arama yapamıyor ve taradığın kod diğer cihaza ulaşacak bir adres içermiyor. Ondan yeni bir kod göstermesini iste ve onu tara.',
        'invalid_code' => 'Bu kod geçersiz veya süresi dolmuş. Diğer cihazdan yeni bir kod oluşturmasını iste.',
        'already_under_way' => 'Bu cihaz o kodu zaten kabul etti ve diğer cihazın onayını bekliyor. Onay gelmezse yeni bir kod iste ve onu kullan.',
        'vouched_but_refused' => 'Diğer cihaz o kodu hâlâ tutuyor, ancak bu cihaz onu kabul edemedi. Ondan yeni bir kod iste ve onu kullan.',
        'code_incomplete' => 'Bu kod eksik. Diğer cihazdakiyle karşılaştır ve tamamını gir.',
        'code_not_accepted' => 'Bu ağdaki hiçbir cihaz bu kodu kabul etmedi. Kodu ve diğer cihazın onu hâlâ gösterip göstermediğini kontrol et.',
        'no_peer_answered' => 'Bu ağda bu koda hiçbir şey yanıt vermedi. Diğer cihazda eşitlemenin çalıştığını kontrol et ya da kodunu kamerayla tara — kameranın ağda arama yapması gerekmez.',
        'no_peer_answered_ios' => 'Bu ağda bu koda hiçbir şey yanıt vermedi. Diğer cihazı ağda aramak iPhone’da henüz çalışmıyor, bu yüzden kodunu kamerayla tara.',
        'no_peer_answered_camera_off' => 'Bu ağda bu koda hiçbir şey yanıt vermedi. Diğer cihazı ağda aramak iPhone’da henüz çalışmıyor ve kamera erişimi kapalı — bu yüzden cihaz ayarlarından Beatrax için kamera erişimini yeniden aç ve diğer cihazdaki kodu tara.',
        'rate_limited' => 'Çok fazla deneme. Bir dakika bekle ve tekrar dene.',
        'identity_locked' => 'Cihaz kimliğin kilitli. Uygulamanın kilidini açıp yeniden dene.',
        'identity_needs_lock' => 'Önce uygulama kilidini ayarlayın — cihaz kimliğinizi o korur.',
        'safety_number_changed' => 'Karşılaştırırken diğer cihaz değişti. Onaylamadan önce aşağıdaki kelimeleri tekrar kontrol et.',
    ],
];
