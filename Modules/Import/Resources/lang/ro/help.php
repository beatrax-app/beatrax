<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Ce va face confirmarea cu fiecare rând. „:new” se adaugă în evidențele tale; „:duplicate” este deja acolo dintr-un import anterior și se sare peste el, așa că reimportarea unui extras care se suprapune peste unul existent nu costă nimic; „:enriched” se potrivește cu un rând pe care îl ai deja și completează un detaliu pe care primul fișier nu îl aducea, fără să adauge un al doilea. Până acum nimic de pe acest ecran nu ți-a atins evidențele — „:confirm” este momentul în care o face.',
];
