<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Un paiement en règle souvent plusieurs autres : un relevé de carte prélevé sur le compte bancaire couvre un mois d’achats par carte, et un retrait bancaire finance un paiement par portefeuille effectué quelques jours plus tôt. Une chaîne enregistre quel débit a payé quoi, de sorte qu’un achat figurant sur un relevé peut être remonté jusqu’à l’argent réellement sorti du compte. Beatrax relie tout seul les cas certains et laisse les autres dans la file de vérification. Confirmez quelques fois le même type de lien et il cesse de vous le demander.',
];
