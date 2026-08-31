<?php

declare(strict_types=1);

namespace Modules\CashBook\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Enums\PaymentType;
use Modules\Import\Public\Enums\SyntheticSourceFormat;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Ledger\Public\Services\BaseCurrency;

// The two rows a hand-entered transaction has to hang on — the reader's cash
// account and the run every manual entry is filed under. Both are singletons
// per reader, both are minted on the first entry, and neither is a decision
// the reader makes, so they are owned here rather than by the write itself.
final readonly class ManualEntryAnchors
{
    // Language-neutral on purpose: the slug is derived from this rather than
    // from the account's name, so it stays one spelling whatever the reader
    // reads in. Nothing looks an account up by slug, and re-slugging on every
    // language change would churn unique(user_id, slug) for no reader.
    private const string SLUG_SEED = 'cash';

    private const string OWN_IBAN_PREFIX = 'CASH';

    private const int OWN_IBAN_DIGITS = 12;

    // The line's own English, for the one case where the line cannot be
    // reached: a translation key is not a name, and accounts.name is drawn
    // on eight screens that would print it verbatim.
    private const string UNTRANSLATED = 'Cash';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private BaseCurrency $baseCurrency,
        private AccountSlugResolver $accountSlugs,
    ) {}

    // The slug comes from the ledger's own walk rather than from the user id:
    // `cash-<id>` is a spelling the walk also hands out, so a user whose id is
    // 2 and who already owns an account named "Cash 2" collided on
    // unique(user_id, slug) and lost the cash book outright.
    public function accountIdFor(User $user): ?int
    {
        $now = $this->clock->now()->toDateTimeString();

        $accountId = $this->findOrCreate('accounts', ['user_id' => $user->id, 'kind' => AccountKind::Cash->value], [
            'user_id' => $user->id,
            'name' => self::readersWord(),
            'slug' => $this->accountSlugs->resolveUnique($user->id, self::SLUG_SEED),
            'kind' => AccountKind::Cash->value,
            'iban' => self::ownIban($user->id),
            'default_currency' => $this->baseCurrency->code(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($accountId !== null) {
            $this->relabelIfItIsTheAppsOwn($accountId, $user->id);
        }

        return $accountId;
    }

    public function runIdFor(User $user): ?int
    {
        $now = $this->clock->now()->toDateTimeString();

        return $this->findOrCreate('import_runs', ['user_id' => $user->id, 'source_format' => SyntheticSourceFormat::Manual->value], [
            'user_id' => $user->id,
            'source_format' => SyntheticSourceFormat::Manual->value,
            'raw_file_path' => SyntheticSourceFormat::Manual->value,
            'sha256' => str_repeat('0', 64),
            'uploaded_at' => $now,
            'status' => 'confirmed',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // The reader can relabel the cash account in /settings like any other, and
    // an entry booked in a currency the account does not name never joins the
    // line pots, /reconcile and the forecast anchor read.
    public function currencyFor(int $accountId, User $user): string
    {
        $currency = $this->db->connection()
            ->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->value('default_currency');

        return is_string($currency) && $currency !== '' ? $currency : $this->baseCurrency->code();
    }

    // The name is data, so it froze in whatever language the first entry was
    // typed in and a reader who switched language kept reading the old word.
    // The whole predicate is in the statement: nothing is read back, and an
    // account already carrying the reader's word is not written to at all.
    private function relabelIfItIsTheAppsOwn(int $accountId, int $userId): void
    {
        $word = self::readersWord();

        $this->db->connection()
            ->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $userId)
            ->where('kind', AccountKind::Cash->value)
            ->where('iban', self::ownIban($userId))
            ->where('name', '!=', $word)
            ->update(['name' => $word]);
    }

    // The IBAN this action writes and nothing else does, which is what says an
    // account is one the app minted rather than one a person named: an account
    // a reader called "Cash" came from the import wizard and carries the
    // statement's own IBAN, and the demo cash wallet carries the demo's.
    private static function ownIban(int $userId): string
    {
        return self::OWN_IBAN_PREFIX.str_pad((string) $userId, self::OWN_IBAN_DIGITS, '0', STR_PAD_LEFT);
    }

    // The word the app already ships for this money in all 26 locales, on the
    // payment-type chip every row this account holds carries. Naming the
    // account after its rows' own chip keeps one word for one thing rather
    // than opening a second register for the same noun.
    private static function readersWord(): string
    {
        $key = 'import::payment_type.'.PaymentType::Cash->value;
        $word = Lang::get($key);

        return $word === $key ? self::UNTRANSLATED : $word;
    }

    // The id is read back by the match, never taken from insertGetId():
    // lastInsertId() is per connection and not per table, and the sidebar's
    // own listener writes a `cache` row from inside this INSERT's event.
    /**
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $attributes
     *
     * @link ../../../../.docs/features/core/an-id-read-after-an-insert.md
     */
    private function findOrCreate(string $table, array $match, array $attributes): ?int
    {
        $connection = $this->db->connection();
        $find = static fn (): mixed => $connection->table($table)->where($match)->value('id');

        $existing = $find();
        if (is_numeric($existing)) {
            return (int) $existing;
        }

        try {
            $connection->table($table)->insert($attributes);
        } catch (QueryException $e) {
            // Re-selects on a unique violation, so two adds racing to create
            // the singleton cash account never surface as a 500.
            $raced = $find();
            if (is_numeric($raced)) {
                return (int) $raced;
            }
            throw $e;
        }

        $created = $find();

        return is_numeric($created) ? (int) $created : null;
    }
}
