<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Penger som har kommet inn og ennå ikke har en konvolutt: inntektene i denne perioden, pluss det du lot være ufordelt forrige periode, minus alt som er fordelt nedenfor. Få det ned til null, så står ingenting uplanlagt igjen. Under null betyr at du har fordelt mer enn det som faktisk har kommet inn — ta noe tilbake fra en konvolutt, eller vent på neste lønning.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Hva som skjer med en konvolutt som har brukt mer enn den inneholder, når perioden er over. Velger du ”:reduce”, trekkes underskuddet først fra det du har å fordele neste periode, og konvolutten selv starter på null igjen. Velger du ”:carry”, blir underskuddet stående der det oppsto: konvolutten åpner under null og må fylles opp igjen før den betaler for noe, og resten av planen røres ikke.',
];
