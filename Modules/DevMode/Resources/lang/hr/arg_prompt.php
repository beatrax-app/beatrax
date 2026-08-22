<?php

declare(strict_types=1);

return [
    'heading' => 'Pokreni naredbu',
    'pick_command' => 'Odaberi naredbu iz palete da vidiš njezine argumente.',
    'no_args' => 'Ova naredba ne prima argumente — pošalji da je pokreneš.',
    'required_aria' => 'obavezno',
    'select_placeholder' => '— odaberi —',
    'enable' => 'Omogući',
    'cancel' => 'Odustani',
    'run_command' => 'Pokreni naredbu',

    'errors' => [
        'unknown_command' => 'Nepoznata naredba: :command',
        'missing' => 'Nedostaje :noun: :list',
        'invalid_args' => 'Jedan ili više argumenata nije valjan.',
        // i18n-review: hr · errors.arg — no numeral reaches this noun, so the arms
        // are plain number forms rather than the case a numeral would govern.
        // "Nedostaje" in errors.missing stays singular either way; a native should
        // say whether that pair reads.
        'arg' => 'argument|argumenti|argumenti',
    ],
];
