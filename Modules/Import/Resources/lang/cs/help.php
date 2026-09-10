<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/import/architecture.md#applying-enrichments */
    'preview_status' => 'Co potvrzení udělá s každým řádkem. „:new“ se přidá do tvých záznamů; „:duplicate“ tam už z dřívějšího importu je a přeskočí se, takže znovu načíst výpis, který se překrývá s tím, co už máš, nic nestojí; „:enriched“ patří k řádku, který už máš, a doplní detail, který první soubor nenesl, aniž by přidal druhý. Zatím se nic na této obrazovce tvých záznamů nedotklo — „:confirm“ je ten okamžik.',
];
