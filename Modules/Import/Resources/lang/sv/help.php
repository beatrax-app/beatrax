<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Vad en bekräftelse gör med varje rad. ”:new” läggs till i din bokföring; ”:duplicate” finns redan där från en tidigare import och hoppas över, så att läsa in ett utdrag som överlappar ett du redan har kostar ingenting; ”:enriched” hör till en rad du redan har och fyller i detaljer som den första filen inte bar med sig, utan att lägga till en andra. Ingenting på den här skärmen har ännu rört din bokföring — ”:confirm” är ögonblicket då det sker.',
];
