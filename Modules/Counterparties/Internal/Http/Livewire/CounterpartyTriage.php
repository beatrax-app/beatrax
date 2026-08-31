<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Internal\Actions\LabelCounterparty;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Queries\CounterpartyTriageQueue;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

final class CounterpartyTriage extends Component
{
    // Empirical estimate driving the "~{minutes} min remaining" copy on
    // the triage progress bar.
    private const float MINUTES_PER_UNKNOWN = 0.4;

    // self_account is never materialised as a row and unknown is what the
    // reader is here to leave, so neither is offered by the manual picker.
    private const array MANUAL_LABEL_TYPES = [
        CounterpartyType::Merchant,
        CounterpartyType::Personal,
        CounterpartyType::Bank,
        CounterpartyType::Government,
    ];

    public int $currentIndex = 0;

    public bool $showSuggestion = true;

    public ?int $queueFirstId = null;

    /** @var list<int> ids already labelled in this session (accept / ignore / label leave the queue mid-render, so the row is filtered rather than re-queried) */
    public array $sessionDoneIds = [];

    public string $draftName = '';

    public string $draftType = CounterpartyType::Merchant->value;

    // What the reader typed for a counterparty they have not decided yet, kept
    // per row so moving through the queue does not throw it away. On the phone
    // a typed name vanished on the way to the next card and did not come back
    // on the way to the previous one.
    /** @var array<int, array<string, mixed>> a forged payload can carry any shape here, so loadDraft() reads each field through is_string */
    public array $drafts = [];

    public function mount(?int $queue_first = null): void
    {
        if ($queue_first !== null) {
            $this->queueFirstId = $queue_first;
        }
    }

    public function nextItem(CurrentUser $currentUser, CounterpartyTriageQueue $queue): void
    {
        $this->moveCursor(1, $currentUser, $queue);
    }

    public function previousItem(CurrentUser $currentUser, CounterpartyTriageQueue $queue): void
    {
        $this->moveCursor(-1, $currentUser, $queue);
    }

    public function skipForNow(CurrentUser $currentUser, CounterpartyTriageQueue $queue): void
    {
        $this->moveCursor(1, $currentUser, $queue);
    }

    public function rejectSuggestion(): void
    {
        $this->showSuggestion = false;
    }

    public function acceptSuggestion(
        CurrentUser $currentUser,
        CounterpartyTriageQueue $queue,
        LabelCounterparty $labeller,
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

        $labeller->label(
            $current,
            $currentUser->id(),
            CounterpartyType::Merchant,
            $suggestion->suggestedCounterpartyName,
            $suggestion->suggestedCounterpartyName,
            $session,
        );

        $this->recordDecision($current, $currentUser, $queue);
    }

    public function markIgnored(
        CurrentUser $currentUser,
        CounterpartyTriageQueue $queue,
        LabelCounterparty $labeller,
    ): void {
        $current = $this->resolveCurrent($currentUser, $queue);
        if ($current === null) {
            return;
        }

        $labeller->ignore($current, $currentUser->id());

        $this->recordDecision($current, $currentUser, $queue);
    }

    // The injected collaborators come first and the two typed values last:
    // the view calls this with neither and the properties supply them, while
    // Laravel's positional binding still hands `call('manualLabel', $name,
    // $type)` to the two that cannot be resolved from the container.
    public function manualLabel(
        CurrentUser $currentUser,
        CounterpartyTriageQueue $queue,
        LabelCounterparty $labeller,
        Session $session,
        ?string $name = null,
        ?string $type = null,
    ): void {
        $current = $this->resolveCurrent($currentUser, $queue);
        if ($current === null) {
            return;
        }

        $name = trim($name ?? $this->draftName);
        $labelled = CounterpartyType::tryFrom($type ?? $this->draftType);

        // Said rather than swallowed: a blank name made the one button that
        // records a decision do nothing at all, with no line on the screen to
        // say why. The type cannot be wrong from the picker, so only the name
        // earns a message.
        if ($name === '') {
            $this->addError('draftName', Lang::get('counterparties::triage.name_required'));

            return;
        }

        if ($labelled === null || ! in_array($labelled, self::MANUAL_LABEL_TYPES, true)) {
            return;
        }

        $this->resetErrorBag('draftName');

        // merchant_name is the column merchant_aliases.friendly_name anchors
        // against for retention, so only a merchant earns one.
        $labeller->label(
            $current,
            $currentUser->id(),
            $labelled,
            $name,
            $labelled === CounterpartyType::Merchant ? $name : null,
            $session,
        );

        $this->recordDecision($current, $currentUser, $queue);
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
        $sessionDoneIds = $this->sessionDoneIds;
        /** @var list<Counterparty> $remainingItems */
        $remainingItems = array_values(array_filter(
            $items,
            static fn (Counterparty $cp): bool => ! in_array($cp->id, $sessionDoneIds, true),
        ));

        $current = $remainingItems[$this->currentIndex] ?? null;
        $suggestion = $current !== null && $this->showSuggestion ? $queue->suggestionFor($current) : null;

        $seen = count($this->sessionDoneIds);

        // The queue is re-read each render and a labelled counterparty leaves
        // it, so count($items) fell by one for every one handled: a session
        // that opened at "0 of 12" read "6 of 6 · 100 %" with six still
        // unlabelled. The denominator is the work done plus the work left.
        $remainingCount = count($remainingItems);
        $total = $seen + $remainingCount;

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
            'hasPrevious' => $this->currentIndex > 0,
        ]);
    }

    // The cursor and the draft move together. Without the stash/restore pair
    // the typed name lived only in Alpine, so a Livewire re-render dropped it:
    // typing a name and pressing Next lost it, and Previous did not bring it
    // back.
    private function moveCursor(int $step, CurrentUser $currentUser, CounterpartyTriageQueue $queue): void
    {
        $this->showSuggestion = true;

        if ($step < 0 && $this->currentIndex === 0) {
            return;
        }

        $this->stashDraft($this->resolveCurrent($currentUser, $queue));
        $this->currentIndex += $step;
        $this->loadDraft($this->resolveCurrent($currentUser, $queue));
    }

    // The cursor does NOT advance here. A decided row leaves `remaining` on the
    // next render, so the row after it slides into the index the cursor already
    // holds — incrementing as well stepped over it, and one unknown in every
    // labelled pair was never offered.
    private function recordDecision(Counterparty $decided, CurrentUser $currentUser, CounterpartyTriageQueue $queue): void
    {
        unset($this->drafts[$decided->id]);
        $this->sessionDoneIds[] = $decided->id;
        $this->showSuggestion = true;
        $this->loadDraft($this->resolveCurrent($currentUser, $queue));
    }

    private function stashDraft(?Counterparty $leaving): void
    {
        if ($leaving === null) {
            return;
        }

        if (trim($this->draftName) === '') {
            unset($this->drafts[$leaving->id]);

            return;
        }

        $this->drafts[$leaving->id] = ['name' => $this->draftName, 'type' => $this->draftType];
    }

    // Read through is_string rather than trusted: $drafts is a public property
    // and therefore arrives in the request payload, so a forged shape would
    // otherwise assign an array to a string-typed property and fatal.
    private function loadDraft(?Counterparty $arriving): void
    {
        $draft = $arriving === null ? [] : ($this->drafts[$arriving->id] ?? []);

        $this->draftName = is_string($draft['name'] ?? null) ? $draft['name'] : '';
        $this->draftType = is_string($draft['type'] ?? null) ? $draft['type'] : CounterpartyType::Merchant->value;
        $this->resetErrorBag('draftName');
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

        // settled_amount_minor, not amount_minor: this list is the evidence for
        // a total the index and profile already print from the settled figure,
        // and a card charged in dollars showed the reader $14.20 against a
        // €12.67 the same row reads everywhere else in the app.
        $rows = $db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('counterparty_id', $cp->id)
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'posted_at', 'description', 'settled_amount_minor', 'settled_currency']);

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
