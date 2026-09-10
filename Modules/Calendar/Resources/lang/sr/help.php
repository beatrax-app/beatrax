<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Iz kojih se računa gradi predviđeno dnevno stanje. Susedna kolona „:entries” odlučuje nešto drugo — hoće li se plaćanja s tog računa uopšte crtati u mreži — pa račun može biti vidljiv, a da se ne broji, ili se brojati, a da nije vidljiv. Gde se to dvoje razilazi, dan to kaže ispod svog stanja, umesto da se iznos tiho složi od manje nego što očekuješ.',
];
