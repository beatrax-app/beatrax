<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Hvad en bekræftelse gør ved hver række. ”:new” lægges til i dit regnskab; ”:duplicate” findes allerede der fra en tidligere import og springes over, så at indlæse et udtog, der overlapper et, du har i forvejen, koster ingenting; ”:enriched” hører til en række, du allerede har, og udfylder detaljer, den første fil ikke bar med sig, uden at tilføje endnu en. Intet på denne skærm har endnu rørt dit regnskab — ”:confirm” er øjeblikket, hvor det sker.',
];
