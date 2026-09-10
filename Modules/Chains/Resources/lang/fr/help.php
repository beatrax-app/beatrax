<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Un paiement en règle souvent plusieurs autres : un relevé de carte prélevé sur le compte bancaire couvre un mois d’achats par carte, et un retrait bancaire finance un paiement par portefeuille effectué quelques jours plus tôt. Une chaîne enregistre quel débit a payé quoi, de sorte qu’un achat figurant sur un relevé peut être remonté jusqu’à l’argent réellement sorti du compte. Beatrax relie tout seul les cas certains et laisse les autres dans la file de vérification. Confirmez quelques fois le même type de lien et il cesse de vous le demander.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Un indice est un demi-lien : Beatrax a trouvé un côté d’une chaîne de paiement et pas l’autre, alors il a noté ce qu’il a vu plutôt que de deviner le reste. La plupart se résolvent d’eux-mêmes — un règlement attend ici jusqu’à ce que les débits individuels qu’il a payés soient importés, et devient alors une vraie chaîne. Les autres peuvent être écartés sans risque : rien ne change dans vos comptes dans un cas comme dans l’autre.',
];
