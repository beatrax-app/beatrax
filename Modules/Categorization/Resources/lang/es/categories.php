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
    'income' => 'Ingresos',
    'income-salary' => 'Salario',
    'income-refunds' => 'Reembolsos',
    'income-other' => 'Otros ingresos',
    'housing' => 'Vivienda',
    'housing-rent' => 'Alquiler / Hipoteca',
    'housing-utilities' => 'Suministros',
    'housing-internet' => 'Internet y teléfono',
    'groceries' => 'Supermercado',
    'transport' => 'Transporte',
    'transport-public' => 'Transporte público',
    'transport-fuel' => 'Combustible',
    'transport-car' => 'Mantenimiento del coche',
    'insurance' => 'Seguros',
    'insurance-health' => 'Salud',
    'insurance-liability' => 'Responsabilidad civil',
    'insurance-other' => 'Otros',
    'subscriptions' => 'Suscripciones',
    'subscriptions-streaming' => 'Streaming',
    'subscriptions-music' => 'Música',
    'subscriptions-cloud' => 'Nube / Software',
    'subscriptions-memberships' => 'Membresías',
    'eating-out' => 'Comer fuera',
    'cash-withdrawal' => 'Retirada de efectivo',
    'healthcare' => 'Salud',
    'personal-care' => 'Cuidado personal',
    'donations' => 'Donaciones',
    'transfers-internal' => 'Transferencias (internas)',
    'fees' => 'Comisiones',
];
