<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Z akých účtov sa skladá odhadovaný denný zostatok. Susedný stĺpec „:entries“ rozhoduje o niečom inom — či sa platby toho účtu vôbec kreslia do mriežky — takže účet môže byť vidieť bez toho, aby sa počítal, alebo sa počítať bez toho, aby bol vidieť. Kde sa tie dve rozídu, deň to povie pod svojím zostatkom, namiesto toho, aby sa číslo potichu poskladalo z menšieho, než čakáš.',
];
