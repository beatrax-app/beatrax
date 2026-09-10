<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Sporiaca obálka odkladá peniaze vnútri účtu namiesto toho, aby ich z neho odviedla, takže zostatok v banke sa nezmení, keď do nej vložíš alebo z nej vyberieš. Pri každom účte sú tri sumy: „:real“ je to, čo drží banka, „:allocated“ je to, čo si tvoje obálky dohromady nárokujú, a „:unallocated“ je zvyšok — jediná časť, ktorú sa dá ešte minúť. Keď posledná suma klesne pod nulu, obálky si nárokujú viac, než na účte je, a každý zostatok je nadsadený, kým niečo nevezmeš späť.',
];
