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
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * `/counterparties/triage` focused single-card queue for labelling
 * unknown counterparties. Keyboard-first ergonomics:
 *
 *   Y → accept current suggestion + advance
 *   N → reject suggestion + focus manual-label section
 *   S → skip for now (re-queues at end of session)
 *   → → next unknown
 *   Esc → close triage (return to /counterparties)
 *
 * The keyboard bindings respect the input-carve-out documented in
 * `resources/views/layouts/app.blade.php` — when focus is inside an
 * INPUT / TEXTAREA / contentEditable element, the keys go to the field
 * not the handler. The view layer attaches the listeners on the wire
 * root with Alpine focus-state tracking so the carve-out is honoured.
 *
 * Progress copy is rendered verbatim per UI-SPEC:
 *   `{seen} of {total} · {percent} % · ~{minutes} min remaining`
 *
 * No constructor DI; method-parameter DI throughout.
 */
final class CounterpartyTriage extends Component
{
    /** Estimated minutes-per-unknown for the "~{minutes} min remaining" copy. */
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

        // Promote the unknown row to a merchant counterparty using the
        // suggested name. The resolver's collision-suffixing rule (see
        // CounterpartyResolverService) is bypassed here because the
        // accept path operates on an existing row's id; the
        // (user_id, slug) UNIQUE is preserved by keeping the unknown
        // row's slug intact and only flipping its type + display_name.
        //
        // CRYPT-01 (14.1-13): route display_name/merchant_name through
        // the codec before save() — mirrors
        // CounterpartyResolverService::upsert()'s encryptAttrs() call on
        // the normal create path. Pass-through no-op for a
        // non-encrypted user.
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

        $allowedTypes = ['merchant', 'personal', 'bank', 'government'];
        $name = trim($name);
        if ($name === '' || ! in_array($type, $allowedTypes, true)) {
            return;
        }

        // CRYPT-01 (14.1-13): route display_name/merchant_name through
        // the codec before save(), mirroring acceptSuggestion() above.
        $attrs = ['display_name' => $name];
        if ($type === 'merchant') {
            $attrs['merchant_name'] = $name;
        }
        $encrypted = $codec->encryptAttrs('counterparties', $attrs, $currentUser->id(), $session);

        $current->display_name = is_string($encrypted['display_name'] ?? null)
            ? $encrypted['display_name']
            : $name;
        $current->type = $type;
        if ($type === 'merchant') {
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
        $percent = $total > 0 ? (int) round(($seen / $total) * 100) : 100;
        $remainingCount = max(0, $total - $seen);
        $minutes = (int) max(1, round($remainingCount * self::MINUTES_PER_UNKNOWN));

        $recentTransactions = [];
        if ($current !== null && $current->user_id !== null) {
            $recentTransactions = $this->recentTransactionsFor($current, $db);
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

    /**
     * Resolves the currently-rendered unknown counterparty. Returns
     * null when the queue is empty or the cursor has walked past the
     * last item — the wire actions then no-op rather than throwing.
     */
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
     * Recent transactions tied to the current unknown — feeds the
     * `Recent transactions on this IBAN` section of the triage card.
     *
     * @return list<\stdClass>
     */
    private function recentTransactionsFor(Counterparty $cp, DatabaseManager $db): array
    {
        return array_values($db->connection()->table('transactions')
            ->where('user_id', $cp->user_id)
            ->where('counterparty_id', $cp->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'posted_at', 'description', 'amount_minor'])
            ->all());
    }
}
