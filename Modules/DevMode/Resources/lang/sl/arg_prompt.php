<?php

declare(strict_types=1);

return [
    'heading' => 'Zaženi ukaz',
    'pick_command' => 'Izberi ukaz iz palete, da vidiš njegove argumente.',
    'no_args' => 'Ta ukaz ne sprejema argumentov — pošlji, da ga zaženeš.',
    'required_aria' => 'obvezno',
    'select_placeholder' => '— izberi —',
    'enable' => 'Omogoči',
    'cancel' => 'Prekliči',
    'run_command' => 'Zaženi ukaz',

    'errors' => [
        'unknown_command' => 'Neznan ukaz: :command',
        'missing' => 'Manjka :noun: :list',
        'invalid_args' => 'Eden ali več argumentov ni veljaven.',
        // i18n-review: sl · errors.arg — the case is governed by "Manjka" over in
        // errors.missing and no numeral ever reaches this noun, so the dual has to be
        // carried by the noun alone. That verb cannot agree in number with what lands
        // here; a native should say whether the pair reads at two and at five.
        'arg' => 'argument|argumenta|argumenti|argumenti',
    ],
];
