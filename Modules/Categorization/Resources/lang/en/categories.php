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
    'income' => 'Income',
    'income-salary' => 'Salary',
    'income-refunds' => 'Refunds',
    'income-other' => 'Other income',
    'housing' => 'Housing',
    'housing-rent' => 'Rent / Mortgage',
    'housing-utilities' => 'Utilities',
    'housing-internet' => 'Internet & Phone',
    'groceries' => 'Groceries',
    'transport' => 'Transport',
    'transport-public' => 'Public transport',
    'transport-fuel' => 'Fuel',
    'transport-car' => 'Car maintenance',
    'insurance' => 'Insurance',
    'insurance-health' => 'Health',
    'insurance-liability' => 'Liability',
    'insurance-other' => 'Other',
    'subscriptions' => 'Subscriptions',
    'subscriptions-streaming' => 'Streaming',
    'subscriptions-music' => 'Music',
    'subscriptions-cloud' => 'Cloud / Software',
    'subscriptions-memberships' => 'Memberships',
    'eating-out' => 'Eating out',
    'cash-withdrawal' => 'Cash withdrawal',
    'healthcare' => 'Healthcare',
    'personal-care' => 'Personal care',
    'donations' => 'Donations',
    'transfers-internal' => 'Transfers (internal)',
    'fees' => 'Fees & charges',
];
