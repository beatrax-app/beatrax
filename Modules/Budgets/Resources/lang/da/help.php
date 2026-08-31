<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Penge, der er kommet ind og endnu ikke har en kuvert: denne periodes indtægter, plus det, du ikke fik fordelt i sidste periode, minus alt det, der er fordelt nedenfor. Få det ned på nul, så er intet ufordelt. Under nul betyder, at du har fordelt mere, end der faktisk er kommet ind — tag noget tilbage fra en kuvert, eller vent på næste lønudbetaling.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Hvad der sker med en kuvert, der har brugt mere, end den indeholder, når perioden slutter. Vælger du ”:reduce”, trækkes underskuddet først fra det, du har at fordele i næste periode, og kuverten selv starter forfra på nul. Vælger du ”:carry”, bliver underskuddet stående, hvor det opstod: kuverten åbner under nul og skal fyldes op igen, før den betaler for noget, og resten af planen rører sig ikke.',
];
