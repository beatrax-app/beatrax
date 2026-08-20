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

final readonly class DeepLinkResolver
{
    // target_kind values that never carry a deletable per-user entity -
    // always live, never disabled. inbox/import are the coalesced-import
    // trigger's equivalent; ics-import is a static settings anchor,
    // never a per-user deletable row.
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

    // Extracts the (target_kind, target_id) pair from the decoded params.
    // A missing decode or an off-shape value for either key collapses that
    // slot to null rather than throwing.
    /**
     * @return array{0: ?string, 1: ?int}
     */
    private function readTarget(string $notificationId, User $user): array
    {
        $params = $this->decodeParams($notificationId, $user);
        if ($params === null) {
            return [null, null];
        }

        $targetKind = is_string($params['target_kind'] ?? null) ? $params['target_kind'] : null;
        $targetId = is_numeric($params['target_id'] ?? null) ? (int) $params['target_id'] : null;

        return [$targetKind, $targetId];
    }

    // Re-reads, decrypts, and JSON-decodes the notification's own params
    // column, user-scoped. Every failure mode - a missing row, a
    // non-string/empty payload, unencryptable or malformed JSON, or a
    // non-array shape - collapses to null rather than throwing.
    /**
     * @return array<array-key, mixed>|null
     */
    private function decodeParams(string $notificationId, User $user): ?array
    {
        $row = $this->db->connection()->table('notifications')
            ->where('id', $notificationId)
            ->where('user_id', $user->id)
            ->first(['params']);

        $raw = $row instanceof stdClass ? $row->params ?? null : null;
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decrypted = $this->codec->decryptRow('notifications', ['params' => $raw], $user->id, $this->session);
        $paramsJson = $decrypted['params'] ?? null;
        if (! is_string($paramsJson) || $paramsJson === '') {
            return null;
        }

        try {
            $params = json_decode($paramsJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $params = null;
        }

        return is_array($params) ? $params : null;
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function resolveTarget(?string $targetKind, ?int $targetId, User $user): array
    {
        if ($targetKind !== null && in_array($targetKind, self::ALWAYS_LIVE_KINDS, true)) {
            $url = $this->urlForAlwaysLive($targetKind);

            return [$url, $url === null];
        }

        if ($targetKind === null || $targetId === null) {
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
                // The guided ICS file-import card's anchor - same target
                // PersistIcsStatementReady::handle() passes as its
                // deepLinkRoute OS-push argument.
                'ics-import' => $this->urls->route('settings.open-banking').'#ics-import',
                default => null,
            };
        } catch (RouteNotFoundException) {
            // An always-live kind whose route is not registered (e.g.
            // ics-import when the optional OpenBanking module is
            // disabled) degrades to a disabled link, consistent with
            // the other resolver arms, rather than 500-ing /notifications.
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

    // The Budgets module's write-dead category_budgets table is not the
    // existence source - canBudget() checks whether $categoryId is
    // still a live, user-visible expense category, which is what "the
    // budget target still exists" means under the envelope model.
    /**
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

    // No dedicated Ledger Public existence method exists for a bare
    // transaction id. Reads the transactions table directly via
    // DatabaseManager, user-scoped - the same cross-module raw-table-read
    // shape CounterpartyProfileQuery already uses for its own reads.
    /**
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
