<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Ce que la confirmation fera de chaque ligne. « :new » est ajoutée à vos comptes ; « :duplicate » y figure déjà depuis un import précédent et est ignorée, réimporter un relevé qui chevauche un autre ne coûte donc rien ; « :enriched » correspond à une ligne que vous avez déjà et complète un détail que le premier fichier ne portait pas, sans en créer une seconde. Rien sur cet écran n’a encore touché vos comptes : « :confirm » est le moment où cela se produit.',
];
