<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/pots/architecture.md#reconciliation-real-allocated-unallocated */
    'pots' => 'Une cagnotte met de l’argent de côté à l’intérieur d’un compte au lieu de l’en sortir : le solde chez votre banque ne bouge pas quand vous l’alimentez ou que vous y reprenez de l’argent. Chaque compte affiche trois montants : « :real » est ce que la banque détient, « :allocated » ce que vos cagnottes ont réclamé ensemble, et « :unallocated » ce qu’il reste — la seule part encore libre d’être dépensée. Quand ce dernier montant passe sous zéro, les cagnottes réclament plus que ce que le compte contient, et chaque solde de cagnotte est surévalué tant que vous n’en reprenez pas une part.',
];
