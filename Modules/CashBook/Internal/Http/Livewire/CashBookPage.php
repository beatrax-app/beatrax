<?php

declare(strict_types=1);

namespace Modules\CashBook\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Component;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;

/**
 * @see RecordManualTransaction
 */
final class CashBookPage extends Component
{
    use HandlesTaxTagging;

    public string $direction = 'expense';

    public string $amount = '';

    public string $date = '';

    public string $counterparty = '';

    public ?int $categoryId = null;

    public string $description = '';

    public string $error = '';

    public function mount(Clock $clock): void
    {
        $this->date = $clock->now()->toDateString();
    }

    public function add(CurrentUser $currentUser, RecordManualTransaction $record, DatabaseManager $db): void
    {
        $this->error = '';

        $amountMinor = self::parseAmount($this->amount);
        if ($amountMinor === null || $amountMinor <= 0) {
            $this->error = 'Enter an amount greater than zero.';

            return;
        }

        $date = self::parseDate($this->date);
        if ($date === null) {
            $this->error = 'Enter a valid date.';

            return;
        }

        if (! in_array($this->direction, ['expense', 'income'], true)) {
            $this->direction = 'expense';
        }

        $user = $currentUser->user();

        $record(
            $user,
            $this->direction,
            $amountMinor,
            $date,
            $this->counterparty,
            $this->ownedCategoryId($db, $user->id),
            $this->description !== '' ? $this->description : null,
        );

        $this->reset(['amount', 'counterparty', 'description']);
        $this->categoryId = null;
        $this->dispatch('toast', message: 'Cash entry added.');
    }

    public function delete(int $transactionId, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $db->connection()->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $currentUser->user()->id)
            ->where('source_format', 'manual')
            ->delete();

        $this->dispatch('toast', message: 'Cash entry removed.');
    }

    public function render(
        CurrentUser $currentUser,
        DatabaseManager $db,
        ViewFactory $views,
        TaxTagQuery $taxTagQuery,
        SensitiveColumnCodec $codec,
        Session $session,
    ): View {
        $user = $currentUser->user();
        $connection = $db->connection();

        $entries = $connection->table('transactions as t')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->where('t.user_id', $user->id)
            ->where('t.source_format', 'manual')
            ->orderByDesc('t.posted_at')
            ->orderByDesc('t.id')
            ->limit(100)
            ->get(['t.id', 't.posted_at', 't.counterparty_name', 't.settled_amount_minor', 't.type', 'c.name as category_name']);

        // counterparty_name is ciphertext at rest and the raw query builder
        // applies no cast, so decrypt each entry before it reaches the view.
        // decryptValue is a pass-through no-op for non-encryption users.
        $entries = $entries->map(function (object $entry) use ($codec, $user, $session): object {
            if (is_string($entry->counterparty_name) && $entry->counterparty_name !== '') {
                $entry->counterparty_name = $codec->decryptValue(
                    'transactions',
                    'counterparty_name',
                    $entry->counterparty_name,
                    $user->id,
                    $session,
                )['value'];
            }

            return $entry;
        });

        $categories = $connection->table('categories')
            ->where(static function (Builder $query) use ($user): void {
                $query->whereNull('user_id')->orWhere('user_id', $user->id);
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        $entryIds = array_map(static fn (object $row): int => is_numeric($row->id) ? (int) $row->id : 0, $entries->all());
        $taxState = $this->taxTagStateFor($entryIds, $taxTagQuery, $currentUser);

        $view = $views->make('cashbook::livewire.cash-book-page', [
            'entries' => $entries,
            'categories' => $categories,
            'taxState' => $taxState,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Cash book · beatrax']);

        return $view;
    }

    private function ownedCategoryId(DatabaseManager $db, int $userId): ?int
    {
        if ($this->categoryId === null) {
            return null;
        }

        // The category id comes from a client-controllable Livewire property —
        // only attach it if it is one of the user's own or a shared global
        // category, never another user's private one.
        $owned = $db->connection()->table('categories')
            ->where('id', $this->categoryId)
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->exists();

        return $owned ? $this->categoryId : null;
    }

    // Accepts plain ("12.50"), Dutch grouped ("1.234,56"), and comma-decimal
    // ("12,50") forms; the rightmost of '.' or ',' is the decimal separator.
    // Rejects non-numeric characters, so scientific notation or a leading
    // sign never coerces to a surprise amount.
    private static function parseAmount(string $raw): ?int
    {
        $trimmed = str_replace(' ', '', trim($raw));
        if ($trimmed === '' || preg_match('/[^0-9.,]/', $trimmed) === 1) {
            return null;
        }

        $lastDot = strrpos($trimmed, '.');
        $lastComma = strrpos($trimmed, ',');
        $decimalPos = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);

        $wholeRaw = $decimalPos === -1 ? $trimmed : substr($trimmed, 0, $decimalPos);
        $fracRaw = $decimalPos === -1 ? '' : substr($trimmed, $decimalPos + 1);

        $whole = preg_replace('/[.,]/', '', $wholeRaw) ?? '';
        if (preg_match('/^\d{1,12}$/', $whole) !== 1) {
            return null;
        }
        if ($fracRaw !== '' && preg_match('/^\d+$/', $fracRaw) !== 1) {
            return null;
        }

        $cents = substr(str_pad($fracRaw, 2, '0'), 0, 2);

        return (int) $whole * 100 + (int) $cents;
    }

    private static function parseDate(string $raw): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse(trim($raw))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
