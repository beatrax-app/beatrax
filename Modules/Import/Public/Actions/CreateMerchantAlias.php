<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Services\PatternGeneralizer;

/**
 * Public action that persists one row of the per-user merchant_aliases
 * table. The action is the sole permissible write path from the UI /
 * Livewire / queue worker into merchant_aliases: every call site
 * routes through this action so the idempotent (user_id, pattern)
 * key + the PatternGeneralizer wiring stays in one place.
 *
 * Idempotency contract: `updateOrCreate` keyed on `(user_id, pattern)`.
 * Calling the action twice for the same user + raw description
 * updates the existing row with the latest `generalized_pattern` and
 * `friendly_name` rather than creating a duplicate row. The composite
 * UNIQUE constraint on the table backs this at the DB layer.
 *
 * `generalizedPattern` is optional: when the caller passes null the
 * action derives the value via the injected PatternGeneralizer. The
 * rename popover passes the user-edited generalized pattern from the
 * preview line so the user's manual override wins over the
 * heuristic; programmatic seeders pass null and let the generalizer
 * decide.
 */
final class CreateMerchantAlias
{
    public function __construct(private readonly PatternGeneralizer $generalizer) {}

    public function __invoke(
        User $user,
        string $pattern,
        ?string $generalizedPattern,
        string $friendlyName,
    ): MerchantAlias {
        $resolvedGeneralized = $generalizedPattern ?? $this->generalizer->generalize($pattern);

        return MerchantAlias::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'pattern' => $pattern,
            ],
            [
                'generalized_pattern' => $resolvedGeneralized,
                'friendly_name' => $friendlyName,
            ],
        );
    }
}
