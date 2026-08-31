<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Un pagamento ne paga spesso diversi altri: l’addebito della carta sul conto copre un mese di acquisti con la carta, e un prelievo dalla banca finanzia un pagamento dal portafoglio fatto giorni prima. Una catena registra quale addebito ha pagato che cosa, così un acquisto su un estratto si può risalire fino al denaro uscito davvero dal conto. Beatrax collega da sé i casi certi e lascia gli altri nella coda di revisione. Conferma qualche volta lo stesso tipo di collegamento e smetterà di chiedertelo.',
];
