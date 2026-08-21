<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Import\Public\Services\PatternGeneralizer;

final class CreateMerchantAlias
{
    public function __construct(
        private readonly PatternGeneralizer $generalizer,
        private readonly MerchantNameResolver $resolver,
    ) {}

    public function __invoke(
        User $user,
        string $pattern,
        ?string $generalizedPattern,
        string $friendlyName,
    ): MerchantAlias {
        $resolvedGeneralized = $generalizedPattern ?? $this->generalizer->generalize($pattern);

        $alias = MerchantAlias::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'pattern' => $pattern,
            ],
            [
                'generalized_pattern' => $resolvedGeneralized,
                'friendly_name' => $friendlyName,
            ],
        );

        // The resolver holds this reader's aliases for the life of the
        // container, and the rename popover saves one on a screen whose next
        // render resolves again. Without this it renders the older answer.
        $this->resolver->forget($user->id);

        return $alias;
    }
}
