<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Kasica odvaja novac unutar računa umesto da ga iznosi iz njega, pa se stanje u banci ne menja kad u nju uplatiš ili iz nje podigneš. Uz svaki račun stoje tri iznosa: „:real” je ono što drži banka, „:allocated” je ono što su tvoje kasice ukupno zauzele, a „:unallocated” je ostatak — jedini deo koji je još slobodan za trošenje. Kad poslednji iznos padne ispod nule, kasice zauzimaju više nego što račun ima, i svako stanje je precenjeno dok nešto ne vratiš.',
];
