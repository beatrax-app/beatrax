<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One unfamiliar IBAN encountered during preview. The wizard's PreviewWizard
 * component renders an inline naming prompt for each entry before allowing
 * the user to confirm the import.
 */
final class UnknownIban extends Data
{
    public function __construct(
        public readonly string $iban,
        public readonly ?string $seenCounterpartyName,
    ) {}
}
