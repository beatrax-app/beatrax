<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'A kivonat dátumok és összegek lapos listája, és semmi nem mondja meg benne, mely sorok ugyanazt az állandó kötelezettséget jelentik. A Beatrax a kedvezményezett szerint csoportosítja a sorokat, eldobja a csoportból kilógó összegeket, és csak akkor javasol sorozatot, ha a köztük lévő rések egyenletes heti, havi, negyedéves vagy éves ritmusba állnak be — ennél szabálytalanabbat egyáltalán nem javasol. Csak addig olvas vissza, ameddig a „:setting” a beállításokban engedi, az pedig a legrövidebb olyan időtávval indul, amellyel egyáltalán dolgozni tud, így egy éves számla addig marad láthatatlan, amíg ki nem bővíted. Itt semmi nem változtat az adataidon, amíg jóvá nem hagyod.',
];
