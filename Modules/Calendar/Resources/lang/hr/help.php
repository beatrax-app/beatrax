<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Iz kojih se računa gradi predviđeno dnevno stanje. Susjedni stupac „:entries” odlučuje nešto drugo — hoće li se plaćanja s tog računa uopće crtati u mreži — pa račun može biti vidljiv, a da se ne broji, ili se brojiti, a da nije vidljiv. Gdje se to dvoje razilazi, dan to kaže ispod svog stanja, umjesto da se iznos tiho složi od manje nego što očekuješ.',
];
