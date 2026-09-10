<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Rapprocher, c’est comparer Beatrax au chiffre de votre banque. Le solde pointé est le solde d’ouverture de ce compte plus chaque ligne que vous avez cochée comme pointée jusqu’à la date du relevé, et l’écart est le chiffre de votre relevé moins ce solde. Cochez ou décochez des lignes dans la liste des opérations jusqu’à ce que l’écart tombe à zéro : cet écran n’invente jamais d’écriture d’ajustement. « :complete » verrouille ensuite les lignes concernées : une ligne verrouillée ne peut plus être modifiée, ventilée ni supprimée tant que vous ne la déverrouillez pas depuis sa propre page.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Où en est une ligne par rapport à votre relevé bancaire. « :uncleared » signifie que Beatrax a l’opération mais que vous ne l’avez pas encore rapprochée d’un relevé ; touchez la pastille pour la passer en « :cleared », et ce sont ces coches que l’écran de rapprochement additionne. « :reconciled » ne se touche pas : c’est un rapprochement terminé qui le pose, et il verrouille la ligne — catégorie, note, ventilation et étiquettes fiscales restent en l’état jusqu’à ce que vous la déverrouilliez.',
];
