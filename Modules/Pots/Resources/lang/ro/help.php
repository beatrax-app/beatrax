<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'O pușculiță pune banii deoparte în interiorul unui cont în loc să îi scoată din el, așa că soldul de la bancă nu se schimbă când o alimentezi sau retragi din ea. La fiecare cont apar trei sume: „:real” este ce ține banca, „:allocated” este ce au revendicat împreună pușculițele tale, iar „:unallocated” este ce rămâne — singura parte încă liberă de cheltuit. Când ultima sumă coboară sub zero, pușculițele revendică mai mult decât are contul, iar fiecare sold este umflat până când iei ceva înapoi.',
];
