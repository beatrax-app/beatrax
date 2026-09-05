<?php

declare(strict_types=1);

/**
 * Exemptions from the sensitive-column predicate guard, keyed at the unit a
 * reader can check: `{repo-relative path}::{column}::{kind}`. One file, one
 * column, one shape of use.
 *
 * There used to be a whole-file exemption instead, granted to any file
 * containing the substring `SensitiveColumnCodec`, `decryptValue`,
 * `encryptValue` or `encryptAttrs` anywhere in it. Seventy-one production
 * files held one, and each was unscanned for every column it touched — so a
 * file earned silence for its predicates by encrypting something else
 * correctly. `CounterpartyResolverService` was one of them, and the IBAN it
 * copied into a slug and into a URL lived there behind that exemption.
 *
 * Nothing lands here that the guard can work out for itself. A call naming a
 * table the registry does not seal is cleared by reading the call; so is a
 * write whose value goes through the codec. What is left is a claim a person
 * has to make, so it names the column it rests on, and that column has to be
 * one SensitiveFieldRegistry::knowinglyPlaintext() records.
 *
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#how-the-predicate-guard-decides-what-it-is-looking-at
 *
 * @return array<string, string> path::column::kind => why this call is safe
 */
return [
    'Modules/CashBook/Internal/Services/ManualEntryAnchors.php::iban::write' => "findOrCreate('accounts', …) names its table in the call's own first argument rather than in a ->table() the chain reader can follow, so the guard cannot tell which iban this is. It is accounts.iban, the synthetic marker IBAN the cash book mints for its own account.",
];
