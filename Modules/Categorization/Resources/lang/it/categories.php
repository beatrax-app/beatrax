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
    'income' => 'Entrate',
    'income-salary' => 'Stipendio',
    'income-refunds' => 'Rimborsi',
    'income-other' => 'Altre entrate',
    'housing' => 'Casa',
    'housing-rent' => 'Affitto / Mutuo',
    'housing-utilities' => 'Utenze',
    'housing-internet' => 'Internet e telefono',
    'groceries' => 'Spesa',
    'transport' => 'Trasporti',
    'transport-public' => 'Trasporto pubblico',
    'transport-fuel' => 'Carburante',
    'transport-car' => 'Manutenzione auto',
    'insurance' => 'Assicurazioni',
    'insurance-health' => 'Salute',
    'insurance-liability' => 'Responsabilità civile',
    'insurance-other' => 'Altro',
    'subscriptions' => 'Abbonamenti',
    'subscriptions-streaming' => 'Streaming',
    'subscriptions-music' => 'Musica',
    'subscriptions-cloud' => 'Cloud / Software',
    'subscriptions-memberships' => 'Iscrizioni',
    'eating-out' => 'Ristoranti',
    'cash-withdrawal' => 'Prelievo contanti',
    'healthcare' => 'Sanità',
    'personal-care' => 'Cura personale',
    'donations' => 'Donazioni',
    'transfers-internal' => 'Giroconti (interni)',
    'fees' => 'Commissioni',
];
