<?php

declare(strict_types=1);

return [
    'heading_named' => ':name grandinė',
    'heading' => 'Grandinė',

    'unresolved_heading' => 'Nepasirinkta jokia operacija',
    'unresolved_body' => 'Operacijų sąraše pasirink eilutę, kad pamatytum, kas ją apmokėjo.',

    'none_heading' => 'Finansavimo grandinės nerasta',
    'none_body' => 'Šiai operacijai finansavimo grandinės neaptikta. Jei jos tikėjaisi, pateik kandidatą iš peržiūros eilės.',

    'none_beyond_leg' => 'Toliau už šios atkarpos finansavimo grandinės nerasta.',

    'covers_charges' => 'Padengia :count ICS mokėjimą|Padengia :count ICS mokėjimus|Padengia :count ICS mokėjimų',
    'show_more_fanout' => 'Rodyti dar :count · :shown iš :total',

    'confirm' => 'Patvirtinti',
    'reject' => 'Atmesti',
    'confirm_aria' => 'Patvirtinti grandinės sąsają :id',
    'reject_aria' => 'Atmesti grandinės sąsają :id',

    'confidence_tier' => [
        'deterministic' => 'Deterministinis',
        'confirmed' => 'Patvirtinta',
        'candidate' => 'Kandidatas',
    ],

    'confidence_aria' => [
        'deterministic' => 'Patikimumas: deterministinis atitikimas',
        'confirmed' => 'Patikimumas: patvirtinta',
        'candidate' => 'Patikimumas: kandidatas, reikia peržiūrėti',
    ],
];
