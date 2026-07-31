<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Chains\Public\Exceptions\ChainLinkRequiresConcretePartnerException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/chains/architecture.md
 */
final class ConfirmChainLink
{
    private const AUTO_PROMOTE_THRESHOLD = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
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

        $this->db->connection()->transaction(function () use ($link, $user): void {
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

            if ($confirmedCount < self::AUTO_PROMOTE_THRESHOLD) {
                return;
            }

            $now = $this->clock->now()->toDateTimeString();
            $this->db->connection()->table('chain_links')
                ->where('user_id', $user->id)
                ->where('state', ChainLinkState::Candidate->value)
                ->whereJsonContains('evidence->signature_hash', $signatureHash)
                ->update([
                    'state' => ChainLinkState::Confirmed->value,
                    'resolver' => 'rule',
                    'updated_at' => $now,
                ]);
        });
    }
}
