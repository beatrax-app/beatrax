<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Tek bir ödeme çoğu zaman birkaç ödemeyi birden karşılar: banka hesabındaki kart ekstresi bir aylık kart alışverişini kapatır, bankadan yapılan bir çekim ise günler önce yapılmış bir cüzdan ödemesini finanse eder. Zincir, hangi borçlandırmanın neyi ödediğini kaydeder; böylece bir ekstredeki alışveriş, hesabından gerçekten çıkan paraya kadar izlenebilir. Beatrax emin olduğu bağlantıları kendisi kurar, kalanları ise inceleme kuyruğunda sana bırakır. Aynı türden bağlantıyı birkaç kez onayla, o türü artık sormayı bıraksın.',
];
