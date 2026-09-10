<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Saskaņošana nozīmē salīdzināt Beatrax ar pašas bankas skaitli. Saskaņotais atlikums ir šā konta sākuma atlikums plus katra rinda, ko esi atzīmējis kā nokārtotu līdz pārskata datumam, un starpība ir tavā pārskatā redzamais skaitlis mīnus šis atlikums. Atzīmē vai noņem atzīmes darījumu sarakstā, līdz starpība sasniedz nulli — šis ekrāns nekad neizdomā izlīdzinošu ierakstu. Pēc tam „:complete“ bloķē aptvertās rindas: bloķētu rindu nevar rediģēt, sadalīt vai dzēst, kamēr to atkal neatbloķē tās pašas lapā.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Kur rinda atrodas attiecībā pret tavu bankas izrakstu. „:uncleared“ nozīmē, ka Beatrax darījumu jau ir saņēmusi, bet tu vēl neesi to salīdzinājis ar izrakstu; pieskaries plāksnītei, lai tā kļūtu „:cleared“ — tieši šīs atzīmes saskaņošanas ekrāns saskaita. „:reconciled“ nav pieskarama: to uzliek pabeigta saskaņošana, kas rindu noslēdz, tāpēc kategorija, piezīme, sadalījums un nodokļu atzīmes paliek tādas, kādas ir, līdz tu to atkal atslēdz.',
];
