<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Denaro già arrivato che non ha ancora una busta: le entrate di questo periodo, più quanto era rimasto non assegnato nel periodo precedente, meno tutto ciò che è assegnato qui sotto. Portalo a zero e non resta nulla senza un piano. Sotto zero hai assegnato più di quanto sia davvero entrato: riprendi qualcosa da una busta oppure aspetta la prossima entrata.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Che cosa succede a una busta che ha speso più di quanto contiene, una volta finito il periodo. Con “:reduce” lo scoperto viene tolto per primo da ciò che avrai da distribuire nel periodo successivo, e la busta stessa riparte da zero. Con “:carry” lo scoperto resta dov’è nato: quella busta apre sotto zero e va riempita di nuovo prima di pagare qualsiasi cosa, e il resto del piano non si muove.',
];
