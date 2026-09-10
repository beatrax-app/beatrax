<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Öngörülen bir ödemenin hangi hesaba sayılacağı. Bazı ödemeler, çıkıyormuş gibi göründükleri hesaptan hiç çıkmaz: kartla yapılan bir alışveriş daha sonra banka hesabından tek bir borçlandırmayla kapatılır, cüzdandan yapılan bir ödeme ise bir aktarımla beslenir. Bunu açtığında her biri onu gerçekten ödeyen hesaba sayılır; böylece bir hesabın çizgisi, yanından geçen değil, içinden gerçekten geçecek olan parayı gösterir.',
];
