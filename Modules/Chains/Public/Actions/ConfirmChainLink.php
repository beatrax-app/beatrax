<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\AutoPromotion;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Internal\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\EntityMutated;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ConfirmChainLink
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
    ) {}

    public function __invoke(int $chainLinkId, User $user): void
    {
        /** @var ChainLink|null $link */
        $link = ChainLink::query()
            ->where('id', $chainLinkId)
            ->where('user_id', $user->id)
            ->first();

        if ($link === null) {
            // Thrown explicitly (rather than via firstOrFail's
            // ModelNotFoundException) so the contract is testable outside
            // the HTTP boundary, where the framework isn't there to convert it.
            throw new NotFoundHttpException('Chain link not found.');
        }

        if ($link->to_transaction_id === null) {
            // Hint-shaped rows may carry a NULL endpoint only while
            // candidate; the schema trigger refuses to flip them to
            // confirmed. Trip the typed exception before the save so the
            // caller renders a readable error instead of an SQLSTATE 23000.
            throw ChainLinkRequiresConcretePartnerException::from($link);
        }

        /** @var list<int> $promoted */
        $promoted = [];

        $this->db->connection()->transaction(function () use ($link, $user, &$promoted): void {
            // Preserve the resolver value the resolver wrote (typically
            // 'auto'); the user merely confirmed an existing suggestion.
            $link->state = ChainLinkState::Confirmed->value;
            $link->save();

            $evidence = $link->evidence;
            $signatureHash = $evidence['signature_hash'] ?? null;
            if (! is_string($signatureHash) || $signatureHash === '') {
                return;
            }

            $confirmedCount = $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', ChainLinkState::Confirmed->value)
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->count();

            if ($confirmedCount < AutoPromotion::THRESHOLD) {
                return;
            }

            $now = $this->clock->now()->toDateTimeString();
            // The same guard the single-link path above trips on: an
            // exceeded-tolerance hint shares its statement's signature hash
            // with every confirmed link on it, and the schema trigger aborts
            // the whole confirm rather than let a NULL endpoint be promoted.

            // The ids are read before the UPDATE: afterwards the predicate
            // matches nothing and the peer is told about no rows at all.
            foreach ($this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', ChainLinkState::Candidate->value)
                ->whereNotNull('to_transaction_id')
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->pluck('id') as $id) {
                $promoted[] = is_numeric($id) ? (int) $id : 0;
            }

            if ($promoted === []) {
                return;
            }

            $this->db->connection()->table('chain_links')
                ->whereIn('id', $promoted)
                ->update([
                    'state' => ChainLinkState::Confirmed->value,
                    'resolver' => ChainLinkResolver::Rule->value,
                    'updated_at' => $now,
                ]);
        });

        $this->capture($link->id, $user, ['state' => ChainLinkState::Confirmed->value]);

        foreach ($promoted as $id) {
            $this->capture($id, $user, [
                'state' => ChainLinkState::Confirmed->value,
                'resolver' => ChainLinkResolver::Rule->value,
            ]);
        }
    }

    /**
     * @param  array<string, scalar|null>  $dirtyFields
     */
    private function capture(int $chainLinkId, User $user, array $dirtyFields): void
    {
        if ($chainLinkId <= 0) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'chain_links',
            pk: $chainLinkId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: $dirtyFields,
        ));
    }
}
