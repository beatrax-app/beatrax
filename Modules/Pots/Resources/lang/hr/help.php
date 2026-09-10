<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Kasica odvaja novac unutar računa umjesto da ga iznosi iz njega, pa se stanje u banci ne mijenja kad u nju uplatiš ili iz nje podigneš. Uz svaki račun stoje tri iznosa: „:real” je ono što drži banka, „:allocated” je ono što su tvoje kasice ukupno zauzele, a „:unallocated” je ostatak — jedini dio koji je još slobodan za trošenje. Kad zadnji iznos padne ispod nule, kasice zauzimaju više nego što račun ima, i svako je stanje precijenjeno dok nešto ne vratiš.',
];
