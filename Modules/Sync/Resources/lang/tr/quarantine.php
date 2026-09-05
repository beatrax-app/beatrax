<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count değişiklik Beatrax uygulamasının daha yeni bir sürümü tarafından yapıldı',
        'body' => 'Reddedilen şey, bu Beatrax sürümünde bulunmayan bir şeye işaret ediyor, bu yüzden bu cihazın onu koyacak bir yeri yoktu. Hâlâ onu yapan cihazda duruyor ve sana ait hiçbir şey silinmedi.',
        'action' => 'Bu cihazda Beatrax uygulamasını güncelle. Güncellemeden sonra yapılan değişiklikler normal şekilde gelir, ama bir kez reddedilen hiçbir şey yeniden gönderilmez — bu cihazda da gerekiyorsa değişikliği burada yeniden yap.',
    ],
    'untrusted_author' => [
        'summary' => ':count değişiklik, bu cihazın tanımadığı bir cihaz tarafından imzalandı',
        'body' => 'Reddedilen şey, bu cihazla hiç eşleştirilmemiş bir cihazdan ya da senin kaldırdığın bir cihazdan geldi. Buraya hiçbir şey yazılmadı ve burada zaten bulunan hiçbir şey değişmedi.',
        'action' => 'O cihazı kendin kaldırdıysan, kaldırmanın yaptığı tam olarak budur ve düzeltilecek bir şey yok. Kaldırmadıysan, bu sayfadaki cihaz listesine bak.',
    ],
    'not_verified' => [
        'summary' => ':count değişiklik bu cihazdaki güvenlik kontrolünden geçemedi',
        'body' => 'Bir imza, değişikliği yaptığını öne süren cihazla eşleşmedi ya da değişiklik başka bir hesaba gönderilmişti. Buraya hiçbir şey yazılmadı. Kendi cihazların arasında bunun olmaması gerekir.',
        'action' => 'Bu sayfadaki cihaz listesine bak ve tanımadığın her şeyi kaldır. Oradaki her cihaz senin ve bu olmaya devam ediyorsa, bu Beatrax uygulamasındaki bir arızadır, buradan düzeltebileceğin bir şey değil.',
    ],
    'diverged' => [
        'summary' => 'Başka bir cihazdan gelen :count değişiklik buraya kaydedilemedi',
        'body' => 'Bu cihazın saklayamadığı bir şey geldi: kendisinin bir parçası eksik olan bir kayıt, var olmayan bir tarih, artık tutmayan bir bölüştürme, iki cihazın daha önce aynı kimliği verdiği bir kayıt ya da burada hâlâ kullanılan bir şey için gelen bir silme. Reddedilen şey diğer cihazında var, bu cihazda yok; yani ikisi artık aynı şeyi tutmuyor.',
        'action' => 'Diğer cihazındaki kaydı burada gördüğünle karşılaştır ve değişikliği burada yeniden yap — ya da başka yerde kaldırdığın bir şey hâlâ buradaysa, burada yeniden sil. Reddedilen hiçbir şey kendiliğinden yeniden gönderilmez.',
    ],
    'last_seen' => 'En son: :when',
];
