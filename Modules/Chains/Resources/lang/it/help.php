<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Un pagamento ne paga spesso diversi altri: l’addebito della carta sul conto copre un mese di acquisti con la carta, e un prelievo dalla banca finanzia un pagamento dal portafoglio fatto giorni prima. Una catena registra quale addebito ha pagato che cosa, così un acquisto su un estratto si può risalire fino al denaro uscito davvero dal conto. Beatrax collega da sé i casi certi e lascia gli altri nella coda di revisione. Conferma qualche volta lo stesso tipo di collegamento e smetterà di chiedertelo.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Un suggerimento è mezzo collegamento: Beatrax ha trovato un lato di una catena di pagamenti e non l’altro, quindi ha annotato quello che ha visto invece di indovinare il resto. La maggior parte si risolve da sola — un regolamento aspetta qui finché non vengono importate le singole spese che ha pagato, e allora diventa una catena vera. Gli altri si possono ignorare tranquillamente, e in ogni caso nel tuo registro non cambia nulla.',
];
