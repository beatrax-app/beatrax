<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Repair;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\ReassignsCounterparty;
use Modules\Ledger\Public\Services\FieldProvenanceWriter;
use Modules\Search\Public\Contracts\SearchIndexRepairContract;
use Modules\Sync\Internal\Merge\PeerAuthoredRows;
use Modules\Sync\Internal\Merge\PeerParentColumn;
use Modules\Sync\Internal\Merge\PeerRowAliases;
use Modules\Sync\Internal\Merge\UntranslatedParentId;
use Modules\Sync\Internal\Merge\UntranslatedParentIds;
use Modules\Sync\Internal\Merge\UntranslatedParentVerdict;
use Modules\Sync\Public\Events\TransactionMutated;

// Repoints the rows `UntranslatedParentIds` can prove are on the wrong parent,
// and refuses every other finding out loud. The write goes through the action
// the detail screen's picker calls, so the mutation is ANNOUNCED: a raw UPDATE
// would leave the backfilled create as the log's latest word on the column.

// Outside `Internal\Merge\` deliberately, and it has to stay outside. A row
// arriving from a peer raises no domain event -- the decision each entry of
// `AN_EVENT_THE_MERGE_NEVER_RAISES` records -- and this is not an arriving row:
// it is a local write, made by this device, which therefore announces itself.
/**
 * @link ../../../../.docs/features/sync/architecture.md#an-id-that-crossed-before-its-alias-existed
 */
final readonly class UntranslatedParentRepair
{
    // The columns a module publishes an announcing writer for. Everything else
    // is refused: the default is refusal, so a column added to the translation
    // map tomorrow is reported and skipped rather than written silently.
    private const array ANNOUNCED = ['transactions.counterparty_id'];

    public const string PROTECTED = 'a hand edit on this device claims the column';

    public const string UNANNOUNCED = 'no writer here announces this column';

    public const string NOT_HERE_YET = 'run sync:repair-stranded-creates first';

    public const string NOTHING_SPEAKS = 'nothing in the log speaks for the id';

    public function __construct(
        private UntranslatedParentIds $census,
        private PeerAuthoredRows $rows,
        private PeerRowAliases $aliases,
        private ReassignsCounterparty $reassign,
        private FieldProvenanceWriter $provenance,
        private Dispatcher $events,
        private ?SearchIndexRepairContract $repairs = null,
    ) {}

    // Every finding with what this device would do about it. Scoped by cause,
    // never by table: the walk is over what the log proves, and a table nobody
    // named here reaches the same refusals a named one would.
    /**
     * @return array{checked: int, agreed: int, plans: list<PlannedRepoint>}
     */
    public function plan(int $userId): array
    {
        $census = $this->census->census($userId);
        $plans = [];

        foreach ($census['findings'] as $finding) {
            $plans[] = new PlannedRepoint($finding, $this->actionFor($finding, $userId));
        }

        return ['checked' => $census['checked'], 'agreed' => $census['agreed'], 'plans' => $plans];
    }

    private function actionFor(UntranslatedParentId $finding, int $userId): string
    {
        if ($finding->verdict !== UntranslatedParentVerdict::Misfiled) {
            return $finding->verdict === UntranslatedParentVerdict::Unplaceable ? self::NOT_HERE_YET : self::NOTHING_SPEAKS;
        }

        if (! in_array($finding->at->columnPath(), self::ANNOUNCED, true)) {
            return self::UNANNOUNCED;
        }

        return $this->claimedByHand($finding, $userId) ? self::PROTECTED : PlannedRepoint::REPOINT;
    }

    // A column the reader stamped is the reader's answer, and it outranks the
    // peer's. Silently overwriting one is worse than leaving the row wrong: the
    // row is visibly wrong, and a lost correction is not.
    private function claimedByHand(UntranslatedParentId $finding, int $userId): bool
    {
        return array_key_exists(
            $finding->at->column,
            $this->provenance->protectedFieldsFor($userId, (int) $finding->at->localId),
        );
    }

    // Writes only the plans marked `repoint`, and records the alias beside each
    // -- the pair `AlreadyPresentCreate` should have written when the create was
    // refused. Without it the next op naming the same peer id crosses untranslated
    // exactly as these did, and the repair would be owed again tomorrow.
    /**
     * @param  list<PlannedRepoint>  $plans
     * @return array{repointed: int, aliased: int, refused: int}
     */
    public function apply(array $plans, User $user): array
    {
        $repointed = 0;
        $aliased = 0;
        $refused = 0;

        foreach ($plans as $plan) {
            if (! $plan->writes()) {
                continue;
            }

            $aliased += $this->rememberTheAlias($plan->finding->at, $user->id) ? 1 : 0;
            $written = $this->repoint($plan->finding, $user);
            $repointed += $written;
            $refused += 1 - $written;
        }

        return ['repointed' => $repointed, 'aliased' => $aliased, 'refused' => $refused];
    }

    // The peer's id and the local row it means, remembered so that translation
    // answers on its own from here on. `remember()` matches on the same natural
    // key the census judged the row by and records nothing where the ids agree.
    private function rememberTheAlias(PeerParentColumn $at, int $userId): bool
    {
        $before = $this->aliases->localFor($at->parentTable, $at->deviceId, $at->peerValue, $userId);

        $this->aliases->remember(
            $at->parentTable,
            $at->deviceId,
            $at->peerValue,
            $this->rows->payload($at->parentTable, $at->peerValue, $at->deviceId, $userId),
            $userId,
        );

        return $before === null && $this->aliases->localFor($at->parentTable, $at->deviceId, $at->peerValue, $userId) !== null;
    }

    // Returns 1 when the row moved. The action answers 0 for a reconciled row,
    // a row of another reader and a value already stored, which is what makes a
    // second run repair nothing without a flag or a list to remember.
    private function repoint(UntranslatedParentId $finding, User $user): int
    {
        $localId = (int) $finding->at->localId;
        $correct = (int) $finding->correctValue;

        if (($this->reassign)($localId, $correct, $user) === 0) {
            return 0;
        }

        $this->events->dispatch(new TransactionMutated(
            transactionId: $localId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: ['counterparty_id' => $correct],
        ));

        // The index doc holds the counterparty's name and this process may hold
        // no key to read it, so the doc is recorded as owed rather than written
        // half-blind; the app's own repair pass rebuilds it while unlocked.
        $this->repairs?->owe($user->id, $localId);

        return 1;
    }
}
