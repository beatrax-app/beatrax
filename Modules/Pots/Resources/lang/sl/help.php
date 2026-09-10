<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Hranilnik odloži denar znotraj računa, namesto da bi ga odnesel z njega, zato se stanje pri banki ne spremeni, ko ga napolniš ali z njega dvigneš. Pri vsakem računu so tri števke: „:real“ je to, kar ima banka, „:allocated“ je to, kar so si tvoji hranilniki skupaj prilastili, „:unallocated“ pa je ostanek — edini del, ki ga je še mogoče porabiti. Ko zadnja števka pade pod ničlo, hranilniki zahtevajo več, kot je na računu, in vsako stanje je previsoko, dokler česa ne vzameš nazaj.',
];
