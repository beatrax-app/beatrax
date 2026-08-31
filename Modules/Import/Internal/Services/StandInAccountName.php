<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Modules\Core\Public\Support\Lang;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// A card statement, a wallet export and a single-account bank export all carry
// no IBAN of the reader's own, so each stands one in. The reader is being asked
// to name that account and cannot act on a stand-in, so a screen showing one
// asks here for the name of the thing it stands for.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-stand-in-for-an-iban-drawn-as-one
 */
final readonly class StandInAccountName
{
    public function __construct(private CsvPresetRegistry $presets) {}

    // The match takes no default arm on purpose: a fourth sentinel has to name
    // the line a reader will see before the analyser will pass it, rather than
    // reaching a screen as its own spelling.
    public function for(string $identifier): ?string
    {
        $sentinel = SyntheticIban::tryFrom($identifier);

        if ($sentinel === null) {
            return $this->presets->ownAccountLabel($identifier);
        }

        return Lang::get(match ($sentinel) {
            SyntheticIban::IcsCard => 'import::preview.ics.name',
            SyntheticIban::Paypal => 'import::preview.paypal.name',
            SyntheticIban::GooglePlay => 'import::preview.google_play.name',
        });
    }
}
