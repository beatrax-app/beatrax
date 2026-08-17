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
    'income' => 'Gelir',
    'income-salary' => 'Maaş',
    'income-refunds' => 'İadeler',
    'income-other' => 'Diğer gelir',
    'housing' => 'Konut',
    'housing-rent' => 'Kira / Konut kredisi',
    'housing-utilities' => 'Faturalar',
    'housing-internet' => 'İnternet ve telefon',
    'groceries' => 'Market',
    'transport' => 'Ulaşım',
    'transport-public' => 'Toplu taşıma',
    'transport-fuel' => 'Yakıt',
    'transport-car' => 'Araç bakımı',
    'insurance' => 'Sigorta',
    'insurance-health' => 'Sağlık',
    'insurance-liability' => 'Sorumluluk',
    'insurance-other' => 'Diğer',
    'subscriptions' => 'Abonelikler',
    'subscriptions-streaming' => 'Yayın',
    'subscriptions-music' => 'Müzik',
    'subscriptions-cloud' => 'Bulut / Yazılım',
    'subscriptions-memberships' => 'Üyelikler',
    'eating-out' => 'Dışarıda yemek',
    'cash-withdrawal' => 'Nakit çekme',
    'healthcare' => 'Sağlık',
    'personal-care' => 'Kişisel bakım',
    'donations' => 'Bağışlar',
    'transfers-internal' => 'Transferler (dahili)',
    'fees' => 'Ücretler',
];
