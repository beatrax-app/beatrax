<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Mutabakat, Beatrax’i bankanın kendi rakamıyla karşılaştırmaktır. Mutabık bakiye, bu hesabın açılış bakiyesi artı ekstre tarihine kadar mutabık olarak işaretlediğin her satırdır; fark ise ekstrendeki rakamın bu bakiyeden çıkarılmış hâlidir. İşlem listesinde satırları işaretleyip işareti kaldırarak farkı sıfıra indir — bu ekran hiçbir zaman denkleştirme kaydı uydurmaz. Ardından “:complete” kapsadığı satırları kilitler: kilitli bir satır, kendi sayfasından yeniden açmadıkça düzenlenemez, bölünemez veya silinemez.',
];
