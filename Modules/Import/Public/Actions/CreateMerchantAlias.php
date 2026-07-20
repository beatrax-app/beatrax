<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Services\PatternGeneralizer;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
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
