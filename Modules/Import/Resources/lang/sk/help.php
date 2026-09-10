<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Čo potvrdenie urobí s každým riadkom. „:new“ sa pridá do tvojich záznamov; „:duplicate“ tam už z predošlého importu je a preskočí sa, takže znovu načítať výpis, ktorý sa prekrýva s tým, čo už máš, nič nestojí; „:enriched“ patrí k riadku, ktorý už máš, a doplní detail, ktorý prvý súbor neniesol, bez toho, aby pridal druhý. Zatiaľ sa nič na tejto obrazovke tvojich záznamov nedotklo — „:confirm“ je ten okamih.',
];
