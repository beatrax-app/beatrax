<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'En betalning betalar ofta för flera andra: en kortavräkning på bankkontot täcker en månads kortköp, och ett uttag från banken finansierar en plånboksbetalning några dagar tidigare. En kedja håller reda på vilken debitering som betalade vad, så att ett köp på ett kontoutdrag kan spåras tillbaka till pengarna som faktiskt lämnade kontot. Beatrax knyter själv ihop de säkra fallen och lämnar resten i granskningskön åt dig. Bekräfta samma sorts koppling några gånger, så slutar den fråga om just den sorten.',
];
