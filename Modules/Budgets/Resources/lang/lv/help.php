<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Nauda, kas jau ir saņemta un kam vēl nav aploksnes: šī perioda ienākumi, plus tas, kas iepriekšējā periodā palika nesadalīts, mīnus viss zemāk sadalītais. Noved to līdz nullei, un nekas nepaliek neieplānots. Zem nulles nozīmē, ka esi sadalījis vairāk, nekā patiesībā ir ienācis — paņem kaut ko atpakaļ no kādas aploksnes vai gaidi nākamo algas dienu.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Kas notiek ar aploksni, kas iztērējusi vairāk, nekā tajā ir, kad periods beidzas. Ar „:reduce“ iztrūkums vispirms tiek atskaitīts no tā, kas tev būs sadalāms nākamajā periodā, un pati aploksne sāk no nulles. Ar „:carry“ iztrūkums paliek tur, kur radās: šī aploksne atveras zem nulles un ir jāpapildina, pirms tā par kaut ko maksā, bet pārējais plāns netiek aiztikts.',
];
