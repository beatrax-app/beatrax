<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Bani care au intrat deja și încă nu au un plic: veniturile acestei perioade, plus ce a rămas nealocat în perioada trecută, minus tot ce este alocat mai jos. Adu-l la zero și nimic nu rămâne neplanificat. Sub zero înseamnă că ai alocat mai mult decât a intrat cu adevărat — ia ceva înapoi dintr-un plic sau așteaptă următorul salariu.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Ce se întâmplă cu un plic care a cheltuit mai mult decât are, odată ce perioada se încheie. Cu „:reduce”, minusul se scade întâi din ce vei avea de alocat perioada următoare, iar plicul însuși pornește din nou de la zero. Cu „:carry”, minusul rămâne acolo unde a apărut: acel plic se deschide sub zero și trebuie completat înainte să mai plătească ceva, iar restul planului nu se clintește.',
];
