<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Afstemmen is Beatrax naast het cijfer van je bank leggen. Het verwerkte saldo is het beginsaldo van deze rekening plus elke regel die je tot en met de afschriftdatum als verwerkt hebt aangevinkt, en het verschil is het bedrag op je afschrift daar minus. Vink regels in de transactielijst aan of uit tot het verschil nul is — dit scherm verzint nooit een sluitpost. ‘:complete’ zet de regels die eronder vallen daarna op slot: een vergrendelde regel kun je niet meer bewerken, splitsen of verwijderen tot je hem op zijn eigen pagina weer vrijgeeft.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Hoe een regel ervoor staat ten opzichte van je bankafschrift. ‘:uncleared’ betekent dat Beatrax de transactie heeft maar dat jij hem nog niet tegen een afschrift hebt gelegd; tik op het bolletje om er ‘:cleared’ van te maken, en juist die vinkjes telt het afstemscherm op. ‘:reconciled’ kun je niet aantikken — dat zet een afgeronde afstemming, en die vergrendelt de regel: categorie, notitie, splitsing en fiscale labels blijven staan tot je hem weer ontgrendelt.',
];
