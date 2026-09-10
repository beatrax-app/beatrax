<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Krājkase noliek naudu malā konta iekšienē, nevis izņem to no tā, tāpēc atlikums bankā nemainās, kad tu to papildini vai no tās izņem. Pie katra konta ir trīs skaitļi: „:real“ ir tas, ko tur banka, „:allocated“ ir tas, ko tavas krājkases kopā ir paņēmušas, un „:unallocated“ ir pārpalikums — vienīgā daļa, ko vēl var brīvi tērēt. Kad pēdējais skaitlis noslīd zem nulles, krājkases prasa vairāk, nekā kontā ir, un katras atlikums ir pārāk liels, līdz tu kaut ko paņem atpakaļ.',
];
