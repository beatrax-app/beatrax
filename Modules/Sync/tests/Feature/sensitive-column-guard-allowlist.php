<?php

declare(strict_types=1);

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 *
 * @return array<string, string> repo-relative path => reason the guard's
 *                               bare-column-name scan hits this file safely (no codec routing needed)
 */
return [
    // The `iban` bare-column match here is `accounts.iban`, a plaintext column
    // SensitiveFieldRegistry never lists (it lists `counterparties.iban`). Every
    // site below predicates a raw Query Builder where('iban', ...) against
    // accounts, so no ciphertext-versus-plaintext mismatch is possible.
    'Modules/Receipts/Internal/Jobs/ProcessFetchedInboxMessagesJob.php' => 'where(\'iban\', ...) targets accounts.iban (own-account match on an inbound bank email) — Section E safe, not SensitiveFieldRegistry-listed.',
    'Modules/Import/Internal/Pipeline/Stages/ClassifyTransactionType.php' => 'where(\'iban\', ...) targets accounts.iban (transfer-classification own-account match against the still-plaintext import-time DTO) — Section E safe.',
    'Modules/Import/Public/Actions/EnsurePaypalAccountAction.php' => 'where(\'iban\', ...) targets accounts.iban (synthetic PayPal account existence check) — Section E safe.',
    'Modules/Import/Public/Services/EloquentAccountResolver.php' => 'where(\'iban\', ...) targets accounts.iban (Eloquent Account lookup by IBAN) — Section E safe.',
    'Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectCardStep.php' => 'where(\'iban\', ...) targets accounts.iban (ICS own-account existence check during onboarding) — Section E safe.',
    'Modules/Onboarding/Internal/Http/Livewire/Steps/ConnectBankStep.php' => 'where(\'iban\', ...) targets accounts.iban (ASN own-account existence check during onboarding) — Section E safe.',
];
