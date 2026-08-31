<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Reconcilierea înseamnă să compari Beatrax cu cifra băncii înseși. Soldul reconciliat este soldul de deschidere al acestui cont plus fiecare rând bifat ca decontat până la data extrasului, iar diferența este cifra din extras minus acest sold. Bifează sau debifează rânduri în lista de tranzacții până când diferența ajunge la zero — acest ecran nu inventează niciodată o înregistrare de echilibrare. „:complete” blochează apoi rândurile acoperite: un rând blocat nu poate fi editat, împărțit sau șters până nu îl deblochezi din pagina lui.',
];
