<?php

declare(strict_types=1);

return [
    'heading_named' => 'Reťazec — :name',
    'heading' => 'Reťazec',

    'unresolved_heading' => 'Reťazec zatiaľ nie je vyhodnotený',
    'unresolved_body' => 'Vyhodnocovanie reťazcov ešte beží. Otvor front na kontrolu alebo o chvíľu obnov stránku.',

    'none_heading' => 'Nenašiel sa žiadny finančný reťazec',
    'none_body' => 'Pri tejto transakcii sa nezistil žiadny finančný reťazec. Ak by tam mal byť, pridaj kandidáta z frontu na kontrolu.',

    'none_beyond_leg' => 'Za týmto úsekom sa nenašiel žiadny finančný reťazec.',

    'covers_charges' => 'Pokrýva :count platbu ICS|Pokrýva :count platby ICS|Pokrýva :count platieb ICS',
    'show_more_fanout' => 'Zobraziť ďalšie: :count · :shown z :total',

    'confirm' => 'Potvrdiť',
    'reject' => 'Odmietnuť',
    'confirm_aria' => 'Potvrdiť článok reťazca :id',
    'reject_aria' => 'Odmietnuť článok reťazca :id',

    'confidence_tier' => [
        'deterministic' => 'Deterministická',
        'confirmed' => 'Potvrdené',
        'candidate' => 'Kandidát',
    ],

    'confidence_aria' => [
        'deterministic' => 'Istota: deterministická zhoda',
        'confirmed' => 'Istota: potvrdené',
        'candidate' => 'Istota: kandidát; treba skontrolovať',
    ],
];
