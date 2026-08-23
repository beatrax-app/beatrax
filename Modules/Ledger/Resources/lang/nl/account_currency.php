<?php

declare(strict_types=1);

return [
    'heading' => 'Rekeningvaluta',
    'intro' => 'De valuta waarin elke rekening luidt. Een nieuwe rekening begint in de basisvaluta.',
    'no_accounts' => 'Nog geen rekeningen.',
    'legend' => 'Valuta voor :name',
    'label' => 'Valuta',
    'help' => 'De valuta waarin deze rekening haar saldo weergeeft.',
    'save' => 'Valuta opslaan',
    'saved' => 'Opgeslagen',

    'toast' => [
        'updated' => ':name rapporteert nu in :currency.',
    ],

    'errors' => [
        'unknown' => 'Dat is geen valuta die deze installatie kent.',
    ],

    'warning' => [
        'intro' => 'Deze rekening van :from naar :to wijzigen geeft haar alleen een ander label. Er wordt niets omgerekend of herschreven.',
        'baseline' => 'Het beginsaldo van :amount blijft exact dat bedrag en wordt voortaan als :to gelezen.',
        'lines' => 'Deze rekening bevat nu:',
        'reads' => 'Na de wijziging rapporteert deze rekening haar :to-regel — nul als ze niets in :to bevat.',
        'confirm' => 'Toch wijzigen',
        'keep' => ':currency behouden',
    ],
];
