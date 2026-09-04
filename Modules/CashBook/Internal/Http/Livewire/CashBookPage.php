<?php

declare(strict_types=1);

namespace Modules\CashBook\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\CashBook\Internal\Actions\RecordManualTransaction;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Core\Public\Support\SafeDate;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Ledger\Public\Services\TransactionStatusQuery;
use Modules\Ledger\Public\Support\CategoryPathName;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Public\Events\TransactionMutated;
use Modules\Sync\Public\Services\DependentRowCascade;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Modules\Tax\Public\Http\Livewire\Concerns\HandlesTaxTagging;
use Modules\Tax\Public\Services\TaxTagQuery;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @see RecordManualTransaction
 */
final class CashBookPage extends Component
{
    use CoercesScalars;
    use DispatchesToast;
    use HandlesTaxTagging;
    use WithPagination;

    // €1250,00 in minor units. Four figures so the worked example also shows
    // the group mark left out, which is the way past the reading that refused
    // the input.
    private const int AMOUNT_EXAMPLE_MINOR = 125_000;

    private const int ENTRIES_PER_PAGE = 25;

    private const string RECONCILED_NOTICE_KEY = 'cashbook::cash-book.toast.reconciled_locked';

    public string $direction = Direction::Expense->value;

    public string $amount = '';

    public string $date = '';

    public string $counterparty = '';

    public ?int $categoryId = null;

    public string $description = '';

    public string $error = '';

    // Which entry is asking. Deleting fired on the first tap with no
    // confirmation and no undo, on a row whose only other control is an amount.
    // Locked because a payload applies its updates BEFORE its calls, so naming
    // the same id in both halves satisfied the comparison in delete() outright.
    #[Locked]
    public ?int $deletingEntryId = null;

    public function mount(Clock $clock): void
    {
        $this->date = $clock->now()->toDateString();
    }

    // WithPagination declares $paginators untyped and public, so a payload
    // could replace the whole map with a scalar; getPage() then indexed a
    // string and setPage() assigned into one, both fatal. Narrowed on every
    // write, which is before any call in the same payload reads it.
    public function updatedPaginators(): void
    {
        $narrowed = [];
        foreach (is_array($this->paginators) ? $this->paginators : [] as $name => $page) {
            if (is_string($name) && is_numeric($page) && (int) $page >= 1) {
                $narrowed[$name] = (int) $page;
            }
        }

        $this->paginators = $narrowed;
    }

    public function add(
        CurrentUser $currentUser,
        RecordManualTransaction $record,
        DatabaseManager $db,
        Translator $translator,
        BaseCurrency $baseCurrency,
        LoggerInterface $logger,
    ): void {
        $this->error = '';

        $user = $currentUser->user();
        $currency = $this->entryCurrency($db, $baseCurrency, $user);

        $amountMinor = MoneyInput::tryToPositiveMinor($this->amount, $currency);
        if ($amountMinor === null) {
            $this->error = $this->amountError($translator->getLocale(), $currency);

            return;
        }

        $date = SafeDate::dayOrNull($this->date);
        if ($date === null) {
            $this->error = Lang::get('cashbook::cash-book.errors.invalid_date');

            return;
        }

        if (Direction::tryFrom($this->direction) === null) {
            $this->direction = Direction::Expense->value;
        }

        // A QueryException's message is the whole statement, its bindings and
        // the database's absolute path. iOS drew that over the app as a native
        // panel on the first entry a clean install ever made.
        try {
            $recorded = $record(
                $user,
                $this->direction,
                $amountMinor,
                $date,
                $this->counterparty,
                $this->ownedCategoryId($db, $user->id),
                $this->description !== '' ? $this->description : null,
            );
        } catch (QueryException $e) {
            $logger->error('CashBookPage: the entry could not be written.', SafeExceptionContext::describe($e));
            $recorded = false;
        }

        // Keeping the fields is the point of saying so: a reader told the entry
        // was not recorded, on a form that had just cleared itself, has to
        // reconstruct what they typed before they can try again.
        if (! $recorded) {
            $this->error = Lang::get('cashbook::cash-book.errors.not_recorded');

            return;
        }

        $this->reset(['amount', 'counterparty', 'description']);
        $this->categoryId = null;

        // The new entry sorts to the head of the list, so a reader who added
        // it from a later page would be told it was added and not be shown it.
        $this->resetPage();

        $this->toast(Lang::get('cashbook::cash-book.toast.added'));
    }

    public function confirmDelete(int|string $transactionId): void
    {
        $this->deletingEntryId = DerivedRowId::fromWire($transactionId);
    }

    public function cancelDelete(): void
    {
        $this->deletingEntryId = null;
    }

    public function delete(
        int|string $transactionId,
        CurrentUser $currentUser,
        DatabaseManager $db,
        Dispatcher $events,
        SearchIndexWriterContract $searchIndex,
        DependentRowCascade $cascade,
    ): void {
        $transactionId = DerivedRowId::fromWire($transactionId);

        // Only the entry confirmDelete() was asked about, which the lock on
        // deletingEntryId is what makes true: the id has to have arrived on an
        // earlier request, so this compares against a server-written value.
        if ($this->deletingEntryId !== $transactionId) {
            return;
        }

        $this->deletingEntryId = null;

        $userId = $currentUser->user()->id;
        $connection = $db->connection();

        $status = $connection->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->where('source_format', SyntheticSourceFormat::Manual->value)
            ->value('status');

        if ($status === null) {
            return;
        }

        if (TransactionStatusQuery::locksEdits($status)) {
            $this->toast(Lang::get(self::RECONCILED_NOTICE_KEY));

            return;
        }

        // Every dependent row goes first and carries its own tombstone, so a
        // peer replaying this never has to be assumed to have FK cascade on.
        $dependents = $cascade->delete('transactions', $transactionId, $userId);

        // The source_format predicate is repeated from the read above rather
        // than trusted from it: this page may only ever delete hand-entered
        // rows, never an imported one.
        $connection->table('transactions')
            ->where('id', $transactionId)
            ->where('user_id', $userId)
            ->where('source_format', SyntheticSourceFormat::Manual->value)
            ->delete();

        // transaction_search_docs holds the deliberate plaintext shadow of the
        // encrypted counterparty name, with no FK, no cascade and no trigger.
        $searchIndex->deleteForTransaction($transactionId, $userId);

        $events->dispatch(new TransactionMutated(
            transactionId: $transactionId,
            userId: $userId,
            mutationType: 'delete',
            dirtyFields: [],
        ));

        foreach ($dependents as $event) {
            $events->dispatch($event);
        }

        $this->toast(Lang::get('cashbook::cash-book.toast.removed'));
    }

    public function render(
        CurrentUser $currentUser,
        DatabaseManager $db,
        ViewFactory $views,
        TaxTagQuery $taxTagQuery,
        SensitiveColumnCodec $codec,
        Session $session,
        BaseCurrency $baseCurrency,
    ): View {
        $user = $currentUser->user();
        $connection = $db->connection();

        $columns = ['t.id', 't.posted_at', 't.counterparty_name', 't.settled_amount_minor', 't.settled_currency', 't.type', ...CategoryPathName::columns('c', 'cp')];

        $entries = $this->manualEntriesQuery($connection, $user->id)
            ->paginate(perPage: self::ENTRIES_PER_PAGE, columns: $columns);

        // Deleting the only entry on the last page left the reader on ?page=2
        // reading "No manual entries yet." over a ledger holding twenty-five
        // rows, with no pagination control drawn and no in-page way back — and
        // it survived a reload, because the page number is in the URL.
        if ($entries->currentPage() > $entries->lastPage()) {
            $this->setPage($entries->lastPage());
            $entries = $this->manualEntriesQuery($connection, $user->id)
                ->paginate(perPage: self::ENTRIES_PER_PAGE, columns: $columns);
        }

        // The raw query builder applies no cast to ciphertext columns.
        // decryptValue is a pass-through for non-encryption users.
        // through() declares its callback over mixed, so the row shape is
        // narrowed here rather than asserted in the signature.
        $entries->through(function (mixed $entry) use ($codec, $user, $session): stdClass {
            if (! $entry instanceof stdClass) {
                return (object) (array) $entry;
            }

            if (is_string($entry->counterparty_name) && $entry->counterparty_name !== '') {
                $entry->counterparty_name = $codec->decryptValue(
                    'transactions',
                    'counterparty_name',
                    $entry->counterparty_name,
                    $user->id,
                    $session,
                )['value'];
            }

            $entry->category_name = CategoryPathName::fromRow($entry);

            return $entry;
        });

        // The group travels with the leaf: the list is flat and alphabetical, so
        // a child sits nowhere near its parent and two groups' leaves collide
        // into two identical options.
        $categoryRows = CategoryPathName::joinParent($connection->table('categories as c'), $user->id, 'c', 'cp')
            ->where(static function (Builder $query) use ($user): void {
                $query->whereNull('c.user_id')->orWhere('c.user_id', $user->id);
            })
            ->get(['c.id', ...CategoryPathName::columns('c', 'cp')]);

        // Two groups named alike leave the qualified paths identical too, and
        // this option carries the money the entry is filed under.
        $labels = CategoryPathName::distinct($categoryRows
            ->mapWithKeys(static fn (stdClass $row): array => [self::toInt($row->id) => CategoryPathName::fromRow($row) ?? ''])
            ->all());

        $categories = $categoryRows
            ->map(static function (stdClass $row) use ($labels): stdClass {
                $row->name = $labels[self::toInt($row->id)];

                return $row;
            })
            ->sort(static function (stdClass $a, stdClass $b): int {
                $byName = LocaleCollator::compare(self::toString($a->name), self::toString($b->name));

                return $byName !== 0 ? $byName : (self::toInt($a->id) <=> self::toInt($b->id));
            })
            ->values();

        $entryIds = array_map(static fn (stdClass $row): int => self::toInt($row->id), $entries->items());
        $taxState = $this->taxTagStateFor($entryIds, $taxTagQuery, $currentUser);

        $view = $views->make('cashbook::livewire.cash-book-page', [
            'entries' => $entries,
            'categories' => $categories,
            'taxState' => $taxState,
            'entryCurrency' => $this->entryCurrency($db, $baseCurrency, $user),
        ]);

        $view->extends('layouts.app', ['title' => Lang::get('cashbook::cash-book.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }

    private function manualEntriesQuery(Connection $connection, int $userId): Builder
    {
        $query = $connection->table('transactions as t')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id');

        return CategoryPathName::joinParent($query, $userId, 'c', 'cp')
            ->where('t.user_id', $userId)
            ->where('t.source_format', SyntheticSourceFormat::Manual->value)
            ->orderByDesc('t.posted_at')
            ->orderByDesc('t.id');
    }

    // The amount field is typed in the cash account's own denomination, and the
    // reader can relabel that account like any other, so the label names what
    // the entry will actually be booked in — and the parser reads it at that
    // currency's scale, which is not a hundredth everywhere.
    private function entryCurrency(DatabaseManager $db, BaseCurrency $baseCurrency, User $user): string
    {
        $cashCurrency = $db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->where('kind', AccountKind::Cash->value)
            ->value('default_currency');

        return is_string($cashCurrency) && $cashCurrency !== ''
            ? $cashCurrency
            : $baseCurrency->forUser($user);
    }

    // An amount the parser could not read is not an amount that is too small.
    // "1.250" is refused because it is ambiguous — grouped thousands or a
    // decimal, a thousand-fold difference — and calling that not-greater-than-
    // zero sends the reader to fix a figure that was never the problem.
    private function amountError(string $locale, string $currency): string
    {
        if (MoneyInput::exceedsMax($this->amount, $currency)) {
            return Lang::get('cashbook::cash-book.errors.amount_too_large');
        }

        // A blank field is genuinely an amount not yet given, so it keeps the
        // prompt rather than being reported as unreadable.
        if (trim($this->amount) === '' || MoneyInput::tryToMinor($this->amount, $currency) !== null) {
            return Lang::get('cashbook::cash-book.errors.amount_positive');
        }

        // What THIS currency takes. A yen reader was told to use "at most two
        // decimals", which is the shape that produced the error, and to leave
        // out a thousands separator the parser in fact accepts.
        $decimals = MoneyInput::decimalPlaces($currency);
        $example = self::amountExample($locale, $currency);

        return $decimals === 0
            ? Lang::get('cashbook::cash-book.errors.amount_unreadable_whole', ['example' => $example])
            : Lang::choice('cashbook::cash-book.errors.amount_unreadable', $decimals, ['decimals' => $decimals, 'example' => $example]);
    }

    // The reader's own decimal mark: telling a Dutch reader to write "1250.00"
    // hands back the very punctuation that started the misreading. Built off
    // the machine-readable form, so the example is always something the parser
    // accepts at this currency's scale.
    private static function amountExample(string $locale, string $currency): string
    {
        $mark = (Locale::tryFrom($locale) ?? Locale::En)->decimalMark();

        return str_replace('.', $mark, MoneyInput::toDecimalString(self::AMOUNT_EXAMPLE_MINOR, $currency));
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
}
