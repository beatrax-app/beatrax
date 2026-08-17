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
    'income' => 'Indtægter',
    'income-salary' => 'Løn',
    'income-refunds' => 'Refusioner',
    'income-other' => 'Andre indtægter',
    'housing' => 'Bolig',
    'housing-rent' => 'Husleje / Realkredit',
    'housing-utilities' => 'Forsyning',
    'housing-internet' => 'Internet og telefon',
    'groceries' => 'Dagligvarer',
    'transport' => 'Transport',
    'transport-public' => 'Offentlig transport',
    'transport-fuel' => 'Brændstof',
    'transport-car' => 'Bilvedligeholdelse',
    'insurance' => 'Forsikring',
    'insurance-health' => 'Sundhed',
    'insurance-liability' => 'Ansvar',
    'insurance-other' => 'Andet',
    'subscriptions' => 'Abonnementer',
    'subscriptions-streaming' => 'Streaming',
    'subscriptions-music' => 'Musik',
    'subscriptions-cloud' => 'Cloud / Software',
    'subscriptions-memberships' => 'Medlemskaber',
    'eating-out' => 'Ude at spise',
    'cash-withdrawal' => 'Kontanthævning',
    'healthcare' => 'Sundhedsudgifter',
    'personal-care' => 'Personlig pleje',
    'donations' => 'Donationer',
    'transfers-internal' => 'Overførsler (interne)',
    'fees' => 'Gebyrer',
];
