<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Un relevé est une liste plate de dates et de montants, et rien n’y dit quelles lignes sont le même engagement récurrent. Beatrax regroupe les lignes par bénéficiaire, écarte les montants qui détonnent dans le groupe et ne propose une série que lorsque les écarts entre elles s’installent dans un rythme régulier — hebdomadaire, mensuel, trimestriel ou annuel. Tout ce qui est moins régulier n’est jamais proposé. Il ne remonte pas au-delà de « :setting » dans les réglages, qui démarre à la plus courte durée exploitable : une facture annuelle reste donc invisible tant que vous ne l’élargissez pas. Rien n’est appliqué à vos données avant votre validation.',
];
