<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use JsonException;
use Modules\Budgets\Public\Services\BudgetProgressQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;
use Modules\Counterparties\Public\Queries\CounterpartyProfileQuery;
use Modules\Notifications\Public\Dto\NotificationDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

final readonly class DeepLinkResolver
{
    // Kinds with no deletable per-user row behind them, so they can never
    // resolve to a dead link.
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
                'dashboard' => Destination::Dashboard->urlFrom($this->urls),
                'forecast' => Destination::Forecasts->urlFrom($this->urls),
                'inbox' => Destination::Email->urlFrom($this->urls),
                'import' => Destination::Imports->urlFrom($this->urls),
                // Must match the deepLinkRoute PersistIcsStatementReady
                // stamps on the OS push.
                'ics-import' => $this->urls->route('settings.open-banking').'#ics-import',
                default => null,
            };
        } catch (RouteNotFoundException) {
            // ics-import with the optional OpenBanking module disabled:
            // degrade to a disabled link rather than 500 /notifications.
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

    // canBudget() is the existence source, not the write-dead
    // category_budgets table: under the envelope model "the budget target
    // exists" means the category is still live and user-visible.
    /**
     * @return array{0: ?string, 1: bool}
     */
    private function resolveBudget(int $categoryId, User $user): array
    {
        if (! $this->budgetProgressQuery->canBudget($user, $categoryId)) {
            return [null, true];
        }

        return [Destination::Budgets->urlFrom($this->urls), false];
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

    // Ledger exposes no Public existence check for a bare transaction id;
    // this raw user-scoped read mirrors CounterpartyProfileQuery's own.
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
