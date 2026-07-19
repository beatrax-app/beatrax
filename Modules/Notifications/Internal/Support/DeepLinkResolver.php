<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * D-25's render-time deep-link existence check — the collaborator that
 * turns a bare `NotificationDto` (deepLinkUrl/deepLinkDisabled/targetKind
 * all null/false, per `NotificationQuery::hydrate()`) into one carrying a
 * validated link. Every call re-derives the answer from CURRENT data; the
 * result is NEVER cached from write time and NEVER computed in a background
 * sweep, because a target can be deleted after delivery and a sweep would
 * leave a staleness window (18-12 planner decision).
 *
 * `notifications.params` is a `SensitiveFieldRegistry`-registered column
 * (18-04) — this class re-reads and decrypts it directly rather than
 * threading it through `NotificationQuery`'s DTO, since 18-12 does not
 * touch that class or `NotificationDto` (both owned by earlier plans).
 *
 * Every existence check re-runs the SAME user-scoped query the deep-link
 * target itself would use (T-18-49): a target belonging to another user
 * resolves exactly like a deleted target — disabled, generic copy, no
 * distinguishing signal. Crosses only through other modules' `Public`
 * seams (`RecurringSeriesQuery`, `BudgetProgressQuery`,
 * `CounterpartyProfileQuery`) plus this module's OWN `notifications` /
 * (read-only) `transactions` table reads via `DatabaseManager` — never an
 * `Internal` import from another module.
 */
final readonly class DeepLinkResolver
{
    /**
     * `target_kind` values that never carry a deletable per-user entity —
     * always live, never disabled. `dashboard`/`forecast` per D-25;
     * `inbox`/`import` are the coalesced-import trigger's equivalent
     * no-deletable-entity targets (PersistCoalescedImport); `ics-import`
     * (Phase 19, Req 14) is `PersistIcsStatementReady`'s equivalent — a
     * static settings anchor, never a per-user deletable row.
     */
    private const ALWAYS_LIVE_KINDS = ['dashboard', 'forecast', 'inbox', 'import', 'ics-import'];

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private Session $session,
        private UrlGenerator $urls,
        private RecurringSeriesQuery $recurringSeriesQuery,
        private BudgetProgressQuery $budgetProgressQuery,
        private CounterpartyProfileQuery $counterpartyProfileQuery,
    ) {}

    public function resolve(NotificationDto $dto, User $user): NotificationDto
    {
        [$targetKind, $targetId] = $this->readTarget($dto->id, $user);
        [$url, $disabled] = $this->resolveTarget($targetKind, $targetId, $user);

        // Defensive fallback so a malformed/unrecognised target never
        // renders a blank "This  no longer exists." sentence.
        $renderedTargetKind = $targetKind ?? ($disabled ? 'item' : null);

        return new NotificationDto(
            id: $dto->id,
            triggerType: $dto->triggerType,
            title: $dto->title,
            body: $dto->body,
            readAt: $dto->readAt,
            dismissedAt: $dto->dismissedAt,
            state: $dto->state,
            createdAt: $dto->createdAt,
            deepLinkUrl: $url,
            deepLinkDisabled: $disabled,
            targetKind: $renderedTargetKind,
            glyph: $dto->glyph,
            typeWord: $dto->typeWord,
        );
    }

    /**
     * Re-reads and decrypts the notification's own `params` column,
     * user-scoped. Never throws — a missing row, unencryptable/malformed
     * JSON, or an unexpected shape all collapse to `[null, null]`.
     *
     * @return array{0: ?string, 1: ?int}
     */
    private function readTarget(string $notificationId, User $user): array
    {
        $row = $this->db->connection()->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $user->id)
            ->first(['params']);

        if (! $row instanceof stdClass) {
            return [null, null];
        }

        $raw = $row->params ?? null;
        if (! is_string($raw) || $raw === '') {
            return [null, null];
        }

        $decrypted = $this->codec->decryptRow('notifications', ['params' => $raw], $user->id, $this->session);
        $paramsJson = $decrypted['params'] ?? null;
        if (! is_string($paramsJson) || $paramsJson === '') {
            return [null, null];
        }

        try {
            $params = json_decode($paramsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [null, null];
        }

        if (! is_array($params)) {
            return [null, null];
        }

        $targetKind = is_string($params['target_kind'] ?? null) ? $params['target_kind'] : null;
        $targetId = is_numeric($params['target_id'] ?? null) ? (int) $params['target_id'] : null;

        return [$targetKind, $targetId];
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function resolveTarget(?string $targetKind, ?int $targetId, User $user): array
    {
        if ($targetKind === null) {
            return [null, true];
        }

        if (in_array($targetKind, self::ALWAYS_LIVE_KINDS, true)) {
            $url = $this->urlForAlwaysLive($targetKind);

            return [$url, $url === null];
        }

        if ($targetId === null) {
            return [null, true];
        }

        return match ($targetKind) {
            'series' => $this->resolveSeries($targetId, $user),
            'budget' => $this->resolveBudget($targetId, $user),
            'counterparty' => $this->resolveCounterparty($targetId, $user),
            'transaction' => $this->resolveTransaction($targetId, $user),
            default => [null, true],
        };
    }

    private function urlForAlwaysLive(string $targetKind): ?string
    {
        try {
            return match ($targetKind) {
                'dashboard' => $this->urls->route('dashboard'),
                'forecast' => $this->urls->route('forecast.index'),
                'inbox' => $this->urls->route('inboxes.index'),
                'import' => $this->urls->route('imports.new'),
                // Phase 19 (Req 14, D-14/D-15): the guided ICS file-import
                // card's anchor (Surface B7, 19-15) — same target
                // `PersistIcsStatementReady::handle()` passes as its
                // `deepLinkRoute` OS-push argument.
                'ics-import' => $this->urls->route('settings.open-banking').'#ics-import',
                default => null,
            };
        } catch (RouteNotFoundException) {
            // WR-15: an always-live kind whose route is not registered
            // (e.g. 'ics-import' when the optional OpenBanking module is
            // disabled) degrades to a disabled link — the caller turns a
            // null url into the [null, true] shape — rather than 500-ing
            // /notifications, consistent with the other resolver arms.
            return null;
        }
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function resolveSeries(int $seriesId, User $user): array
    {
        $series = $this->recurringSeriesQuery->forSeries($seriesId, $user);
        if ($series === null) {
            return [null, true];
        }

        return [$this->urls->route('recurring.series.show', ['seriesId' => $seriesId]), false];
    }

    /**
     * The Budgets module's write-dead `category_budgets` table (superseded
     * by the envelope model, D-20 REDIRECT) is NOT the existence source —
     * `canBudget()` checks whether `$categoryId` is still a live,
     * user-visible expense category, which IS what "the budget target still
     * exists" means under the envelope model (every live expense category
     * is an implicit envelope; deleting/retyping the category is what makes
     * a budget nudge's target disappear).
     *
     * @return array{0: ?string, 1: bool}
     */
    private function resolveBudget(int $categoryId, User $user): array
    {
        if (! $this->budgetProgressQuery->canBudget($user, $categoryId)) {
            return [null, true];
        }

        return [$this->urls->route('budgets.index'), false];
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function resolveCounterparty(int $counterpartyId, User $user): array
    {
        $identity = $this->counterpartyProfileQuery->identityForId($user, $counterpartyId);
        if ($identity === null) {
            return [null, true];
        }

        return [$this->urls->route('counterparties.profile', ['slug' => $identity['slug']]), false];
    }

    /**
     * No dedicated Ledger Public existence method exists for a bare
     * transaction id (the closest, `TransactionStatusQuery::isReconciled`,
     * conflates "missing" with "not reconciled"). Reads the `transactions`
     * table directly via `DatabaseManager`, user-scoped — the same
     * cross-module raw-table-read shape `CounterpartyProfileQuery` already
     * uses for its own recent-activity/category-breakdown reads.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function resolveTransaction(int $transactionId, User $user): array
    {
        $exists = $this->db->connection()->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $exists) {
            return [null, true];
        }

        return [$this->urls->route('transactions.show', ['transactionId' => $transactionId]), false];
    }
}
