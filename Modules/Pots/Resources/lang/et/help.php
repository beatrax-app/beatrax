<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Kogumispott paneb raha konto sees kõrvale, mitte ei vii seda sealt välja, nii et pangas olev jääk ei muutu, kui sa raha lisad või välja võtad. Iga konto juures on kolm arvu: „:real“ on see, mida pank hoiab, „:allocated“ on see, mida su potid kokku on endale võtnud, ja „:unallocated“ on ülejääk — ainus osa, mida saab veel vabalt kulutada. Kui viimane arv langeb alla nulli, võtavad potid endale rohkem, kui kontol on, ja iga poti jääk on liiga suur, kuni sa midagi tagasi ei võta.',
];
