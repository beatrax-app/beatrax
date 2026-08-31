<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Import\Internal\Exceptions\MerchantAliasPatternTooShortException;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Import\Public\Services\PatternGeneralizer;
use Modules\Sync\Public\Events\EntityMutated;

final readonly class CreateMerchantAlias
{
    public function __construct(
        private PatternGeneralizer $generalizer,
        private MerchantNameResolver $resolver,
        private Dispatcher $events,
    ) {}

    public function __invoke(
        User $user,
        string $pattern,
        ?string $generalizedPattern,
        string $friendlyName,
    ): MerchantAlias {
        $resolvedGeneralized = trim($generalizedPattern ?? $this->generalizer->generalize($pattern));

        // The floor belongs here, not on the screen that happens to be open:
        // the generalizer produces sub-floor values on its own ("AH 1234" is
        // "ah"), and the popover never had a minimum at all.
        if (mb_strlen($resolvedGeneralized) < AliasMatchPreviewQuery::MIN_PATTERN_LENGTH) {
            throw new MerchantAliasPatternTooShortException(AliasMatchPreviewQuery::MIN_PATTERN_LENGTH);
        }

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

        // The one merchant_aliases writer that announced nothing, so the rename
        // a reader makes on the row in front of them was the single alias edit
        // that never left the device.
        $this->events->dispatch($alias->wasRecentlyCreated
            ? new EntityMutated(
                table: 'merchant_aliases',
                pk: $alias->id,
                userId: $user->id,
                mutationType: 'create',
                dirtyFields: [
                    'user_id' => $user->id,
                    'pattern' => $pattern,
                    'generalized_pattern' => $resolvedGeneralized,
                    'friendly_name' => $friendlyName,
                ],
            )
            : new EntityMutated(
                table: 'merchant_aliases',
                pk: $alias->id,
                userId: $user->id,
                mutationType: 'edit',
                dirtyFields: [
                    'generalized_pattern' => $resolvedGeneralized,
                    'friendly_name' => $friendlyName,
                ],
            ));

        // The resolver holds this reader's aliases for the life of the
        // container, and the rename popover saves one on a screen whose next
        // render resolves again. Without this it renders the older answer.
        $this->resolver->forget($user->id);

        return $alias;
    }
}
