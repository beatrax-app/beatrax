<?php

declare(strict_types=1);

return [
    'heading_named' => 'Řetězec — :name',
    'heading' => 'Řetězec',

    'unresolved_heading' => 'Není vybraná žádná transakce',
    'unresolved_body' => 'Vyber řádek v seznamu transakcí a uvidíš, co ho zaplatilo.',

    'none_heading' => 'Nenalezen žádný řetězec financování',
    'none_body' => 'U této transakce nebyl zjištěn žádný řetězec financování. Pokud tu nějaký měl být, podej kandidáta z fronty ke kontrole.',

    'none_beyond_leg' => 'Za tímto úsekem nebyl nalezen žádný řetězec financování.',

    'covers_charges' => 'Pokrývá :count platbu ICS|Pokrývá :count platby ICS|Pokrývá :count plateb ICS',
    'show_more_fanout' => 'Zobrazit další: :count · :shown z :total',

    'confirm' => 'Potvrdit',
    'reject' => 'Odmítnout',
    'confirm_aria' => 'Potvrdit vazbu řetězce :id',
    'reject_aria' => 'Odmítnout vazbu řetězce :id',

    'confidence_tier' => [
        'deterministic' => 'Deterministické',
        'confirmed' => 'Potvrzeno',
        'candidate' => 'Kandidát',
    ],

    'confidence_aria' => [
        'deterministic' => 'Jistota: deterministická shoda',
        'confirmed' => 'Jistota: potvrzeno',
        'candidate' => 'Jistota: kandidát; vyžaduje kontrolu',
    ],
];
