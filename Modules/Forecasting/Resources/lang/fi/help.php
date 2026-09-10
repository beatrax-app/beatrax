<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Mille tilille ennustettu maksu lasketaan. Osa maksuista ei koskaan lähde siltä tililtä, jolta ne näyttävät lähtevän: korttiosto katetaan myöhemmin yhdellä veloituksella pankkitililtäsi, ja lompakkomaksu rahoitetaan tilisiirrolla. Kun tämä on päällä, kukin niistä lasketaan sille tilille, joka ne oikeasti maksaa, jolloin tilin viiva näyttää rahat, jotka todella kulkevat sen läpi, eikä niitä jotka vain kävivät ohi.',
];
