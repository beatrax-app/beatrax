<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Mitä vahvistaminen tekee kullekin riville. ”:new” lisätään kirjanpitoosi; ”:duplicate” on jo siellä aiemmasta tuonnista ja ohitetaan, joten päällekkäisen otteen tuominen uudestaan ei maksa mitään; ”:enriched” osuu riviin, joka sinulla jo on, ja täydentää tiedon, jota ensimmäinen tiedosto ei kantanut, lisäämättä toista riviä. Mikään tällä näytöllä ei ole vielä koskenut kirjanpitoosi — ”:confirm” on se hetki.',
];
