<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Saskaņošana nozīmē salīdzināt Beatrax ar pašas bankas skaitli. Saskaņotais atlikums ir šā konta sākuma atlikums plus katra rinda, ko esi atzīmējis kā nokārtotu līdz pārskata datumam, un starpība ir tavā pārskatā redzamais skaitlis mīnus šis atlikums. Atzīmē vai noņem atzīmes darījumu sarakstā, līdz starpība sasniedz nulli — šis ekrāns nekad neizdomā izlīdzinošu ierakstu. Pēc tam „:complete“ bloķē aptvertās rindas: bloķētu rindu nevar rediģēt, sadalīt vai dzēst, kamēr to atkal neatbloķē tās pašas lapā.',
];
