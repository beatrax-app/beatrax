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
    'income' => 'Revenus',
    'income-salary' => 'Salaire',
    'income-refunds' => 'Remboursements',
    'income-other' => 'Autres revenus',
    'housing' => 'Logement',
    'housing-rent' => 'Loyer / Prêt',
    'housing-utilities' => 'Charges',
    'housing-internet' => 'Internet et téléphone',
    'groceries' => 'Courses',
    'transport' => 'Transport',
    'transport-public' => 'Transports en commun',
    'transport-fuel' => 'Carburant',
    'transport-car' => 'Entretien voiture',
    'insurance' => 'Assurances',
    'insurance-health' => 'Santé',
    'insurance-liability' => 'Responsabilité civile',
    'insurance-other' => 'Autres',
    'subscriptions' => 'Abonnements',
    'subscriptions-streaming' => 'Streaming',
    'subscriptions-music' => 'Musique',
    'subscriptions-cloud' => 'Cloud / Logiciels',
    'subscriptions-memberships' => 'Adhésions',
    'eating-out' => 'Restaurants',
    'cash-withdrawal' => 'Retrait d\'espèces',
    'healthcare' => 'Santé',
    'personal-care' => 'Soins personnels',
    'donations' => 'Dons',
    'transfers-internal' => 'Virements (internes)',
    'fees' => 'Frais',
];
