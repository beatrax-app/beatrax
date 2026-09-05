<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Budgets\Public\Dto\BudgetProgressRow;
use Modules\Budgets\Public\Enums\BudgetProgressStatus;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Forecasting\Public\Dto\AccountBalanceLine;
use Modules\Forecasting\Public\Dto\NetWorth;
use Modules\Forecasting\Public\Enums\ShortfallRisk;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Notifications\Internal\Support\NotificationCopyRenderer;
use Modules\Notifications\Internal\Support\NotificationCopySpec;
use Modules\Notifications\Internal\Support\NotificationDraft;
use Modules\Notifications\Internal\Support\NotificationWriter;
use Modules\Notifications\Public\Enums\NotificationTrigger;
use Modules\Position\Public\Dto\PositionSummaryDto;
use Modules\Position\Public\Events\PositionDigestDue;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class PersistPositionDigest
{
    public function __construct(
        private NotificationWriter $writer,
        private UrlGenerator $urls,
        private LoggerInterface $log,
        private NotificationCopyRenderer $copyRenderer,
        private BaseCurrency $baseCurrency,
        private CrossCurrencyTotal $fx,
    ) {}

    public function handle(PositionDigestDue $event): void
    {
        try {
            $copy = NotificationCopySpec::make(
                CopyLine::of($event->cadence === DigestCadence::Daily
                    ? 'notifications::copy.title.position_digest_daily'
                    : 'notifications::copy.title.position_digest_weekly'),
                $this->composeBody($event->position, $event->userId),
            );

            $draft = $this->copyRenderer->forUser($event->userId, fn (): NotificationDraft => NotificationDraft::fromCopy(
                userId: $event->userId,
                triggerType: NotificationTrigger::PositionDigest,
                subjectKey: 'position',
                occurrence: $event->occurrence,
                copy: $copy,
                params: ['target_kind' => 'dashboard'],
                deepLinkRoute: Destination::Dashboard->urlFrom($this->urls),
            ));
            $this->writer->write($draft);
        } catch (Throwable $e) {
            // Swallow - a failed persist must never break the
            // originating digest job run.
            $this->log->error('PersistPositionDigest: failed to persist position digest', [
                ...SafeExceptionContext::describe($e),
                'userId' => $event->userId,
                'cadence' => $event->cadence->value,
            ]);
        }
    }

    // One-line body summarising $position, as the sentences it is built from
    // rather than the built sentence. Amounts ride as value plus currency for
    // the same reason: a string formatted here freezes in whatever locale this
    // process happens to hold, and a scheduled job holds the app default.
    /**
     * @return list<CopyLine>
     */
    private function composeBody(PositionSummaryDto $position, int $userId): array
    {
        $summary = $position->summary;

        $nothingNotable = $summary->isFirstRun
            && $position->budgets === []
            && $position->upcoming === []
            && $position->shortfallRisk !== ShortfallRisk::Ahead;

        if ($nothingNotable) {
            return [CopyLine::of('notifications::copy.digest.nothing_notable')];
        }

        $parts = [CopyLine::of('notifications::copy.digest.flow', [
            'in' => CopyParam::money($summary->inflow->toMinor(), $summary->inflow->currency()),
            'out' => CopyParam::money($summary->outflow->toMinor(), $summary->outflow->currency()),
            'net' => CopyParam::money($summary->net->toMinor(), $summary->net->currency()),
        ])];

        foreach (self::netWorthLines($position->netWorth) as $line) {
            $parts[] = $line;
        }

        foreach ($this->overBudgetLines($position->budgets, $userId) as $line) {
            $parts[] = $line;
        }

        if ($position->upcoming !== []) {
            $parts[] = CopyLine::plural('notifications::copy.digest.payments_due', count($position->upcoming));
        }

        $shortfall = self::shortfallLine($position->shortfallRisk);
        if ($shortfall !== null) {
            $parts[] = $shortfall;
        }

        return $parts;
    }

    // Silence is what the boolean gave a horizon nothing had looked at, and a
    // digest that omits the shortfall line reads the same whether the forecast
    // found nothing or never ran.
    private static function shortfallLine(ShortfallRisk $risk): ?CopyLine
    {
        return match ($risk) {
            ShortfallRisk::Ahead => CopyLine::of('notifications::copy.digest.shortfall'),
            ShortfallRisk::NotYetComputed => CopyLine::of('notifications::copy.digest.forecast_not_run'),
            ShortfallRisk::None => null,
        };
    }

    // The same shape the over-budget roll-up uses: a total with balances left
    // out of it is a smaller net worth unless something says which are missing.
    /**
     * @return list<CopyLine>
     */
    private static function netWorthLines(NetWorth $netWorth): array
    {
        $lines = [CopyLine::of('notifications::copy.digest.net_worth', [
            'amount' => CopyParam::money($netWorth->totalMinor, $netWorth->currency),
        ])];

        $unconverted = self::unconvertedCurrencies($netWorth);
        if ($unconverted !== []) {
            $lines[] = CopyLine::of('core::money.not_converted', ['list' => implode(', ', $unconverted)]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function unconvertedCurrencies(NetWorth $netWorth): array
    {
        $currencies = [];
        foreach ($netWorth->accounts as $line) {
            /** @var AccountBalanceLine $line */
            if ($line->baseEquivalentMinor === null && $line->currency !== $netWorth->currency) {
                $currencies[$line->currency] = true;
            }
        }

        return array_keys($currencies);
    }

    // Envelopes carry the currency they were typed in, so one period can hold a
    // EUR envelope beside a USD one. Adding their minor units gives a figure in
    // no currency at all, printed under whichever code the last row happened to
    // carry — over budget or not.
    /**
     * @param  array<int, BudgetProgressRow>  $budgets
     * @return list<CopyLine>
     */
    private function overBudgetLines(array $budgets, int $userId): array
    {
        $overByCurrency = [];
        foreach ($budgets as $row) {
            if ($row->status === BudgetProgressStatus::Over) {
                $overByCurrency[$row->currency] = ($overByCurrency[$row->currency] ?? 0) - $row->remainingMinor();
            }
        }

        if ($overByCurrency === []) {
            return [];
        }

        $total = $this->fx->of($overByCurrency, $this->ownerCurrency($userId));

        $lines = [];
        if ($total->minor > 0) {
            $lines[] = CopyLine::of('notifications::copy.digest.over_budget', [
                'amount' => CopyParam::money($total->minor, $total->currency),
            ]);
        }

        // The shared line every other roll-up names its gaps with: an
        // understated figure with nothing saying so reads as a smaller
        // overspend rather than a partial one.
        if ($total->isPartial()) {
            $lines[] = CopyLine::of('core::money.not_converted', ['list' => $total->unconvertedList()]);
        }

        return $lines;
    }

    // This listener runs off a queued job with nothing authenticated, where
    // BaseCurrency::code() answers with the install default — neither the
    // reader's currency nor the owner's.
    private function ownerCurrency(int $userId): string
    {
        /** @var User|null $owner */
        $owner = User::query()->where('id', $userId)->first();

        return $owner === null
            ? $this->baseCurrency->installDefault()
            : $this->baseCurrency->forUser($owner);
    }
}
