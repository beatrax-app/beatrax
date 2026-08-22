<?php

declare(strict_types=1);

return [
    'heading' => 'Pokreni komandu',
    'pick_command' => 'Izaberi komandu iz palete da vidiš njene argumente.',
    'no_args' => 'Ova komanda ne prima argumente — pošalji da je pokreneš.',
    'required_aria' => 'obavezno',
    'select_placeholder' => '— izaberi —',
    'enable' => 'Omogući',
    'cancel' => 'Otkaži',
    'run_command' => 'Pokreni komandu',

    'errors' => [
        'unknown_command' => 'Nepoznata komanda: :command',
        'missing' => 'Nedostaje :noun: :list',
        'invalid_args' => 'Jedan ili više argumenata nije ispravan.',
        // i18n-review: sr · errors.arg — no numeral reaches this noun, so the arms
        // are plain number forms rather than the case a numeral would govern.
        // "Nedostaje" in errors.missing stays singular either way; a native should
        // say whether that pair reads.
        'arg' => 'argument|argumenti|argumenti',
    ],
];
