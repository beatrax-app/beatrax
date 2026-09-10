<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Spořicí obálka odkládá peníze uvnitř účtu, místo aby je z něj odváděla, takže zůstatek v bance se nezmění, když do ní vložíš nebo z ní vybereš. U každého účtu jsou tři částky: „:real“ je to, co drží banka, „:allocated“ je to, co si tvé obálky dohromady nárokují, a „:unallocated“ je zbytek — jediná část, kterou je pořád možné utratit. Když poslední částka spadne pod nulu, obálky si nárokují víc, než na účtu je, a každý zůstatek je nadhodnocený, dokud něco nevezmeš zpátky.',
];
