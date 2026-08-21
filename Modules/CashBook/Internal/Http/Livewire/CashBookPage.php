<?php

declare(strict_types=1);

namespace Modules\CashBook\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Component;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;

/**
 * @see RecordManualTransaction
 */
final class CashBookPage extends Component
{
    use DispatchesToast;
    use HandlesTaxTagging;

    // €1250,00 in minor units. Four figures so the worked example also shows
    // the group mark left out, which is the way past the reading that refused
    // the input.
    private const int AMOUNT_EXAMPLE_MINOR = 125_000;

    public string $direction = 'expense';

    public string $amount = '';

    public string $date = '';

    public string $counterparty = '';

    public ?int $categoryId = null;

    public string $description = '';

    public string $error = '';

    // Which entry is asking. Deleting fired on the first tap with no
    // confirmation and no undo, on a row whose only other control is an amount.
    public ?int $deletingEntryId = null;

    public function mount(Clock $clock): void
    {
        $this->date = $clock->now()->toDateString();
    }

    public function add(
        CurrentUser $currentUser,
        RecordManualTransaction $record,
        DatabaseManager $db,
        Translator $translator,
    ): void {
        $this->error = '';

        $amountMinor = MoneyInput::tryToPositiveMinor($this->amount);
        if ($amountMinor === null) {
            $this->error = $this->amountError($translator->getLocale());

            return;
        }

        $date = self::parseDate($this->date);
        if ($date === null) {
            $this->error = Lang::get('cashbook::cash-book.errors.invalid_date');

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
        $this->toast(Lang::get('cashbook::cash-book.toast.added'));
    }

    public function confirmDelete(int $transactionId): void
    {
        $this->deletingEntryId = $transactionId;
    }

    public function cancelDelete(): void
    {
        $this->deletingEntryId = null;
    }

    public function delete(int $transactionId, CurrentUser $currentUser, DatabaseManager $db): void
    {
        // Only the entry that was asked about. A delete arriving for anything
        // else is a client that skipped the question.
        if ($this->deletingEntryId !== $transactionId) {
            return;
        }

        $this->deletingEntryId = null;

        $db->connection()->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $currentUser->user()->id)
            ->where('source_format', 'manual')
            ->delete();

        $this->toast(Lang::get('cashbook::cash-book.toast.removed'));
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

        // The raw query builder applies no cast to ciphertext columns.
        // decryptValue is a pass-through for non-encryption users.
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
        $view->extends('layouts.app', ['title' => Lang::get('cashbook::cash-book.page_title').' · Beatrax']);

        return $view;
    }

    // An amount the parser could not read is not an amount that is too small.
    // "1.250" is refused because it is ambiguous — grouped thousands or a
    // decimal, a thousand-fold difference — and calling that not-greater-than-
    // zero sends the reader to fix a figure that was never the problem.
    private function amountError(string $locale): string
    {
        if (MoneyInput::exceedsMax($this->amount)) {
            return Lang::get('cashbook::cash-book.errors.amount_too_large');
        }

        // A blank field is genuinely an amount not yet given, so it keeps the
        // prompt rather than being reported as unreadable.
        if (trim($this->amount) === '' || MoneyInput::tryToMinor($this->amount) !== null) {
            return Lang::get('cashbook::cash-book.errors.amount_positive');
        }

        return Lang::get('cashbook::cash-book.errors.amount_unreadable', [
            'example' => self::amountExample($locale),
        ]);
    }

    // The reader's own decimal mark: telling a Dutch reader to write "1250.00"
    // hands back the very punctuation that started the misreading. Built off
    // the machine-readable form, so the example is always something the parser
    // accepts.
    private static function amountExample(string $locale): string
    {
        $mark = (Locale::tryFrom($locale) ?? Locale::En)->decimalMark();

        return str_replace('.', $mark, MoneyInput::toDecimalString(self::AMOUNT_EXAMPLE_MINOR));
    }

    private function ownedCategoryId(DatabaseManager $db, int $userId): ?int
    {
        if ($this->categoryId === null) {
            return null;
        }

        // The category id is a client-controllable Livewire property, so it is
        // attached only when it is the user's own or a shared global.
        $owned = $db->connection()->table('categories')
            ->where('id', $this->categoryId)
            ->where(static function (Builder $query) use ($userId): void {
                $query->whereNull('user_id')->orWhere('user_id', $userId);
            })
            ->exists();

        return $owned ? $this->categoryId : null;
    }

    private static function parseDate(string $raw): ?CarbonImmutable
    {
        return SafeDate::parseOrNull(trim($raw))?->startOfDay();
    }
}
