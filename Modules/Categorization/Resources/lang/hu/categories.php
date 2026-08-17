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
    'income' => 'Bevétel',
    'income-salary' => 'Fizetés',
    'income-refunds' => 'Visszatérítések',
    'income-other' => 'Egyéb bevétel',
    'housing' => 'Lakhatás',
    'housing-rent' => 'Bérleti díj / Jelzálog',
    'housing-utilities' => 'Rezsi',
    'housing-internet' => 'Internet és telefon',
    'groceries' => 'Élelmiszer',
    'transport' => 'Közlekedés',
    'transport-public' => 'Tömegközlekedés',
    'transport-fuel' => 'Üzemanyag',
    'transport-car' => 'Autókarbantartás',
    'insurance' => 'Biztosítás',
    'insurance-health' => 'Egészség',
    'insurance-liability' => 'Felelősség',
    'insurance-other' => 'Egyéb',
    'subscriptions' => 'Előfizetések',
    'subscriptions-streaming' => 'Streaming',
    'subscriptions-music' => 'Zene',
    'subscriptions-cloud' => 'Felhő / Szoftver',
    'subscriptions-memberships' => 'Tagságok',
    'eating-out' => 'Étterem',
    'cash-withdrawal' => 'Készpénzfelvétel',
    'healthcare' => 'Egészségügy',
    'personal-care' => 'Testápolás',
    'donations' => 'Adományok',
    'transfers-internal' => 'Átvezetések (belső)',
    'fees' => 'Díjak',
];
