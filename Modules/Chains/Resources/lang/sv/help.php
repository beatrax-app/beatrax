<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'En betalning betalar ofta för flera andra: en kortavräkning på bankkontot täcker en månads kortköp, och ett uttag från banken finansierar en plånboksbetalning några dagar tidigare. En kedja håller reda på vilken debitering som betalade vad, så att ett köp på ett kontoutdrag kan spåras tillbaka till pengarna som faktiskt lämnade kontot. Beatrax knyter själv ihop de säkra fallen och lämnar resten i granskningskön åt dig. Bekräfta samma sorts koppling några gånger, så slutar den fråga om just den sorten.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'En ledtråd är en halv koppling: Beatrax hittade den ena sidan av en betalningskedja men inte den andra, och skrev ner det den såg i stället för att gissa resten. De flesta löser sig själva — en avräkning väntar här tills de enskilda posterna den betalade har importerats, och blir då en riktig kedja. Resten går tryggt att avfärda, och i din bokföring ändras ingenting åt något håll.',
];
