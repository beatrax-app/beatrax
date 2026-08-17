<?php

declare(strict_types=1);

/*
 * Names for the default category tree.
 *
 * Keyed by the slug DefaultCategoryTreeSeeder assigns, which is stable and
 * never shown. The tree is created for every real user, so these are product
 * copy rather than seeded demo data — the names a Dutch user sees on their own
 * budget screen should not be English.
 */
return [
    'income' => 'Inkomster',
    'income-salary' => 'Lön',
    'income-refunds' => 'Återbetalningar',
    'income-other' => 'Övriga inkomster',
    'housing' => 'Boende',
    'housing-rent' => 'Hyra / Bolån',
    'housing-utilities' => 'El och vatten',
    'housing-internet' => 'Internet och telefon',
    'groceries' => 'Matvaror',
    'transport' => 'Transport',
    'transport-public' => 'Kollektivtrafik',
    'transport-fuel' => 'Bränsle',
    'transport-car' => 'Bilunderhåll',
    'insurance' => 'Försäkringar',
    'insurance-health' => 'Hälsa',
    'insurance-liability' => 'Ansvar',
    'insurance-other' => 'Övrigt',
    'subscriptions' => 'Prenumerationer',
    'subscriptions-streaming' => 'Streaming',
    'subscriptions-music' => 'Musik',
    'subscriptions-cloud' => 'Moln / Programvara',
    'subscriptions-memberships' => 'Medlemskap',
    'eating-out' => 'Äta ute',
    'cash-withdrawal' => 'Kontantuttag',
    'healthcare' => 'Vård',
    'personal-care' => 'Personlig vård',
    'donations' => 'Donationer',
    'transfers-internal' => 'Överföringar (interna)',
    'fees' => 'Avgifter',
];
