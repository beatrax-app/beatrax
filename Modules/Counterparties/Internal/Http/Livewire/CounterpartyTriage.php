<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

final class CounterpartyTriage extends Component
{
    // Empirical estimate driving the "~{minutes} min remaining" copy on
    // the triage progress bar.
    private const MINUTES_PER_UNKNOWN = 0.4;

    public int $currentIndex = 0;

    public bool $showSuggestion = true;

    public ?int $queueFirstId = null;

    /** @var list<int> ids already labelled in this session (skip / accept / ignore advance the cursor without removing from the queue mid-render) */
    public array $sessionDoneIds = [];

    public function mount(?int $queue_first = null): void
    {
        if ($queue_first !== null) {
            $this->queueFirstId = $queue_first;
        }
    }

    public function nextItem(): void
    {
        $this->currentIndex++;
        $this->showSuggestion = true;
    }

    public function previousItem(): void
    {
        if ($this->currentIndex > 0) {
            $this->currentIndex--;
        }
        $this->showSuggestion = true;
    }

    public function skipForNow(): void
    {
        $this->nextItem();
    }

    public function rejectSuggestion(): void
    {
        $this->showSuggestion = false;
    }

    public function acceptSuggestion(
        CurrentUser $currentUser,
        CounterpartyTriageQueue $queue,
        SensitiveColumnCodec $codec,
        Session $session,
    ): void {
        $current = $this->resolveCurrent($currentUser, $queue);
        if ($current === null) {
            return;
        }

        $suggestion = $queue->suggestionFor($current);
        if ($suggestion === null) {
            $this->showSuggestion = false;

            return;
        }

        // The resolver's collision-suffixing rule is bypassed here: the
        // accept path reuses the row's existing slug (only type and
        // display_name flip), preserving the (user_id, slug) UNIQUE. The
        // codec call mirrors the normal create path; a no-op for non-encrypted users.
        $encrypted = $codec->encryptAttrs('counterparties', [
            'display_name' => $suggestion->suggestedCounterpartyName,
            'merchant_name' => $suggestion->suggestedCounterpartyName,
        ], $currentUser->id(), $session);

        $current->type = 'merchant';
        $current->display_name = is_string($encrypted['display_name'] ?? null)
            ? $encrypted['display_name']
            : $suggestion->suggestedCounterpartyName;
        $current->merchant_name = is_string($encrypted['merchant_name'] ?? null)
            ? $encrypted['merchant_name']
            : $suggestion->suggestedCounterpartyName;
        $current->save();

        $this->sessionDoneIds[] = $current->id;
        $this->nextItem();
    }

    public function markIgnored(CurrentUser $currentUser, CounterpartyTriageQueue $queue): void
    {
        $current = $this->resolveCurrent($currentUser, $queue);
        if ($current === null) {
            return;
        }

        $metadata = is_array($current->metadata) ? $current->metadata : [];
        $metadata['ignored'] = true;
        $current->metadata = $metadata;
        $current->save();

        $this->sessionDoneIds[] = $current->id;
        $this->nextItem();
    }

    public function manualLabel(
        string $name,
        string $type,
        CurrentUser $currentUser,
        CounterpartyTriageQueue $queue,
        SensitiveColumnCodec $codec,
        Session $session,
    ): void {
        $current = $this->resolveCurrent($currentUser, $queue);
        if ($current === null) {
            return;
        }

        $allowedTypes = [CounterpartyType::Merchant->value, CounterpartyType::Personal->value, CounterpartyType::Bank->value, CounterpartyType::Government->value];
        $name = trim($name);
        if ($name === '' || ! in_array($type, $allowedTypes, true)) {
            return;
        }

        // Codec-before-save, mirroring acceptSuggestion() above.
        $attrs = ['display_name' => $name];
        if ($type === CounterpartyType::Merchant->value) {
            $attrs['merchant_name'] = $name;
        }
        $encrypted = $codec->encryptAttrs('counterparties', $attrs, $currentUser->id(), $session);

        $current->display_name = is_string($encrypted['display_name'] ?? null)
            ? $encrypted['display_name']
            : $name;
        $current->type = $type;
        if ($type === CounterpartyType::Merchant->value) {
            $current->merchant_name = is_string($encrypted['merchant_name'] ?? null)
                ? $encrypted['merchant_name']
                : $name;
        }
        $current->save();

        $this->sessionDoneIds[] = $current->id;
        $this->nextItem();
    }

    public function render(
        CurrentUser $currentUser,
        CounterpartyTriageQueue $queue,
        ViewFactory $views,
        DatabaseManager $db,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $user = $currentUser->user();
        $items = $queue->forUser($user, $this->queueFirstId);
        $total = count($items);
        $sessionDoneIds = $this->sessionDoneIds;
        /** @var list<Counterparty> $remainingItems */
        $remainingItems = array_values(array_filter(
            $items,
            static fn (Counterparty $cp): bool => ! in_array($cp->id, $sessionDoneIds, true),
        ));

        $current = $remainingItems[$this->currentIndex] ?? null;
        $suggestion = $current !== null && $this->showSuggestion ? $queue->suggestionFor($current) : null;

        $seen = count($this->sessionDoneIds);
        $remainingCount = max(0, $total - $seen);

        // Zero of zero is not a hundred per cent: an empty queue reported
        // "0 of 0 · 100 %" above a full bar. Nothing to do is its own state,
        // which the view now draws instead of a bar.
        $percent = $total > 0 ? (int) round(($seen / $total) * 100) : 0;

        // Likewise the estimate: max(1, …) floored an empty queue at one
        // minute of work that does not exist.
        $minutes = $remainingCount > 0
            ? (int) max(1, round($remainingCount * self::MINUTES_PER_UNKNOWN))
            : 0;

        $recentTransactions = [];
        if ($current !== null && $current->user_id !== null) {
            $recentTransactions = $this->recentTransactionsFor($current, $db, $codec, $session);
        }

        return $views->make('counterparties::livewire.counterparty-triage', [
            'current' => $current,
            'suggestion' => $suggestion,
            'showSuggestion' => $this->showSuggestion,
            'seen' => $seen,
            'total' => $total,
            'percent' => $percent,
            'minutesRemaining' => $minutes,
            'remainingCount' => $remainingCount,
            'recentTransactions' => $recentTransactions,
            'queueEmpty' => $current === null,
        ]);
    }

    // Null once the cursor walks past the last item, so wire actions no-op
    // rather than throw.
    private function resolveCurrent(CurrentUser $currentUser, CounterpartyTriageQueue $queue): ?Counterparty
    {
        $items = $queue->forUser($currentUser->user(), $this->queueFirstId);
        $sessionDoneIds = $this->sessionDoneIds;
        /** @var list<Counterparty> $remaining */
        $remaining = array_values(array_filter(
            $items,
            static fn (Counterparty $cp): bool => ! in_array($cp->id, $sessionDoneIds, true),
        ));

        return $remaining[$this->currentIndex] ?? null;
    }

    /**
     * @return list<\stdClass>
     */
    private function recentTransactionsFor(
        Counterparty $cp,
        DatabaseManager $db,
        SensitiveColumnCodec $codec,
        Session $session,
    ): array {
        $userId = $cp->user_id;

        if ($userId === null) {
            return [];
        }

        $rows = $db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('counterparty_id', $cp->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'posted_at', 'description', 'amount_minor']);

        // transactions.description is a SensitiveFieldRegistry column stored
        // as AEAD ciphertext; the raw query builder applies no cast, so
        // decrypt each row before it reaches the view (mirrors
        // CounterpartyTriageQueue::suggestionFor()). Pass-through no-op otherwise.
        return array_values($rows->map(function (\stdClass $tx) use ($codec, $userId, $session): \stdClass {
            if (is_string($tx->description) && $tx->description !== '') {
                $tx->description = $codec->decryptValue(
                    'transactions',
                    'description',
                    $tx->description,
                    $userId,
                    $session,
                )['value'];
            }

            return $tx;
        })->all());
    }
}
